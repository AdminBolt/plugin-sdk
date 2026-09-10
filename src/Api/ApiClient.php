<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api;

use AdminBolt\Plugin\Config;
use AdminBolt\Plugin\Exception\ApiException;
use AdminBolt\Plugin\Exception\TransportException;
use AdminBolt\Plugin\Http\CurlHttpClient;
use AdminBolt\Plugin\Http\HttpClient;
use AdminBolt\Plugin\Http\HttpResponse;
use AdminBolt\Plugin\Logging\Logger;
use AdminBolt\Plugin\Logging\NullLogger;
use AdminBolt\Plugin\Support\Json;

/**
 * Signed transport for the panel's REST API.
 *
 * Authentication is the pair of headers the panel's API key middleware
 * expects, X-API-Key and X-API-Secret, sent on every request. The key is
 * minted by the panel when the plugin is installed and is scoped to the
 * endpoints the manifest declared, so a call outside those scopes comes back
 * 403 rather than silently succeeding.
 */
class ApiClient
{
    /** Methods that may be replayed after a failure without repeating an effect. */
    private const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE'];

    /** @param array<string, string> $defaultHeaders */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly HttpClient $http,
        private readonly array $defaultHeaders = [],
        private readonly int $maxAttempts = 3,
        private readonly Logger $logger = new NullLogger(),
    ) {
    }

    public static function fromConfig(Config $config, string $prefix = '', ?HttpClient $http = null, ?Logger $logger = null): self
    {
        return new self(
            baseUrl: $config->panelUrl . '/api' . $prefix,
            apiKey: $config->apiKey,
            apiSecret: $config->apiSecret,
            http: $http ?? new CurlHttpClient(
                timeout: $config->timeout,
                verifyTls: $config->verifyTls,
                caBundle: $config->caBundle,
            ),
            logger: $logger ?? new NullLogger(),
        );
    }

    /**
     * A copy of this client with extra headers, used to add
     * X-Hosting-Account when an admin key acts on the client API.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        return new static(
            $this->baseUrl,
            $this->apiKey,
            $this->apiSecret,
            $this->http,
            [...$this->defaultHeaders, ...$headers],
            $this->maxAttempts,
            $this->logger,
        );
    }

    public function withPrefix(string $prefix): static
    {
        return new static(
            $this->baseUrl . $prefix,
            $this->apiKey,
            $this->apiSecret,
            $this->http,
            $this->defaultHeaders,
            $this->maxAttempts,
            $this->logger,
        );
    }

    /**
     * @param  array<string, mixed> $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, query: $query);
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, body: $body);
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<mixed>
     */
    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, body: $body);
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<mixed>
     */
    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $path, body: $body);
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<mixed>
     */
    public function delete(string $path, array $body = []): array
    {
        return $this->request('DELETE', $path, body: $body);
    }

    /**
     * @param  array<string, mixed> $query
     * @param  array<string, mixed>|null $body
     * @return array<mixed>
     *
     * @throws ApiException when the panel answers with a non-2xx status
     * @throws TransportException when no answer arrives at all
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);
        $url = $this->url($path, $query);
        $encoded = $body === null ? null : Json::encode($body);

        $headers = [
            'X-API-Key' => $this->apiKey,
            'X-API-Secret' => $this->apiSecret,
            'Accept' => 'application/json',
            'User-Agent' => 'adminbolt-plugin-sdk/1.0 (+https://github.com/AdminBolt/plugin-sdk)',
            ...$this->defaultHeaders,
        ];

        if ($encoded !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->sendWithRetries($method, $url, $headers, $encoded, $path);

        if (!$response->isSuccessful()) {
            throw ApiException::fromResponse($method, $path, $response->status, $response->json(), $response->body);
        }

        return $response->json();
    }

    /**
     * @param array<string, string> $headers
     */
    private function sendWithRetries(string $method, string $url, array $headers, ?string $body, string $path): HttpResponse
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http->send($method, $url, $headers, $body);
            } catch (TransportException $e) {
                // A transport failure on a non-idempotent request is
                // ambiguous: the panel may have created the domain and only
                // the answer was lost. Replaying would create a second one,
                // so the caller is told instead.
                if ($attempt >= $this->maxAttempts || !in_array($method, self::IDEMPOTENT, true)) {
                    throw $e;
                }

                $this->logger->warning('Panel API request failed, retrying', [
                    'method' => $method,
                    'path' => $path,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                $this->backOff($attempt, null);
                continue;
            }

            $retryable = $response->isRetryable()
                // 429 is refused before the panel did any work, so it is safe
                // to replay whatever the method was.
                && ($response->status === 429 || in_array($method, self::IDEMPOTENT, true));

            if (!$retryable || $attempt >= $this->maxAttempts) {
                return $response;
            }

            $this->logger->warning('Panel API answered with a retryable status', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status,
                'attempt' => $attempt,
            ]);

            $this->backOff($attempt, $response->retryAfter());
        }
    }

    private function backOff(int $attempt, ?int $retryAfter): void
    {
        if ($retryAfter !== null) {
            sleep(min($retryAfter, 30));

            return;
        }

        // Exponential with jitter, so a fleet of plugins does not retry in
        // lockstep after a panel restart.
        $base = (int) (250_000 * (2 ** ($attempt - 1)));
        usleep($base + random_int(0, 250_000));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function url(string $path, array $query): string
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
