<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Runtime;

use AdminBolt\Plugin\Config;
use AdminBolt\Plugin\Exception\SignatureException;
use AdminBolt\Plugin\Hook\Dispatcher;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;
use AdminBolt\Plugin\Hook\Signature;
use AdminBolt\Plugin\Logging\Logger;
use AdminBolt\Plugin\Logging\NullLogger;
use AdminBolt\Plugin\Manifest;
use AdminBolt\Plugin\Support\Json;

/**
 * Serves hook deliveries over HTTP.
 *
 * The plugin listens on a local socket or port that the panel reaches; it is
 * not meant to be published to the internet. Authentication is the HMAC
 * signature on every delivery, so even an exposed listener cannot be driven
 * by anyone without the hook secret.
 *
 * Handled requests:
 *
 *   POST  (any path)  a hook delivery, signature required
 *   GET   /health     a liveness probe, signature optional
 *
 * A delivery that fails signature verification answers 401 and no handler
 * runs. Everything else answers 200, with the plugin's decision in the body:
 * see {@see HookResponse}.
 */
final class HttpRuntime
{
    public function __construct(
        private readonly Config $config,
        private readonly Manifest $manifest,
        private readonly Dispatcher $dispatcher,
        private readonly Logger $logger = new NullLogger(),
    ) {
    }

    /**
     * Reads the real request out of the SAPI globals and sends the response.
     */
    public function run(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $body = (string) file_get_contents('php://input');

        $this->handle($method, $path, self::requestHeaders(), $body)->send();
    }

    /**
     * The whole delivery path as a pure function, for tests and for embedding
     * in a plugin that already has its own front controller.
     *
     * @param array<string, string> $headers
     */
    public function handle(string $method, string $path, array $headers, string $body): RuntimeResult
    {
        $headers = array_change_key_case($headers);
        $method = strtoupper($method);

        if ($method === 'GET') {
            return $this->health($headers, $body);
        }

        if ($method !== 'POST') {
            return $this->json(405, ['status' => 'error', 'message' => 'Hook deliveries are POSTed.']);
        }

        try {
            $this->authenticate($headers, $body);
        } catch (SignatureException $e) {
            // Deliberately terse to the caller and detailed in the log: an
            // unauthenticated caller learns nothing about why it failed.
            $this->logger->error('Rejected an unauthenticated hook delivery', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return $this->json(401, ['status' => 'error', 'message' => 'Invalid signature.']);
        }

        try {
            $request = HookRequest::fromJson($body);
        } catch (\Throwable $e) {
            $this->logger->error('Could not read hook envelope', ['error' => $e->getMessage()]);

            return $this->json(400, ['status' => 'error', 'message' => 'Malformed hook envelope.']);
        }

        $started = microtime(true);
        $response = $this->dispatcher->dispatch($request);

        $this->logger->info('Handled hook delivery', [
            'hook' => $request->hook,
            'delivery_id' => $request->deliveryId,
            'status' => $response->status,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        return $this->json($response->httpStatus(), $response->jsonSerialize());
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws SignatureException
     */
    private function authenticate(array $headers, string $body): void
    {
        $signature = $headers[strtolower(Signature::HEADER_SIGNATURE)] ?? null;

        if ($signature === null || $signature === '') {
            throw SignatureException::missingHeader(Signature::HEADER_SIGNATURE);
        }

        $timestamp = $headers[strtolower(Signature::HEADER_TIMESTAMP)] ?? null;

        if ($timestamp === null || !ctype_digit(trim($timestamp))) {
            throw SignatureException::missingHeader(Signature::HEADER_TIMESTAMP);
        }

        Signature::verify(
            $this->config->hookSecret,
            $signature,
            (int) trim($timestamp),
            $body,
            $this->config->signatureTolerance,
        );
    }

    /**
     * Liveness. An unsigned probe gets a bare "ok" and nothing else; a signed
     * one gets the manifest details the panel uses to detect that the
     * installed version drifted from what it registered.
     *
     * @param array<string, string> $headers
     */
    private function health(array $headers, string $body): RuntimeResult
    {
        $signed = isset($headers[strtolower(Signature::HEADER_SIGNATURE)]);

        if (!$signed) {
            return $this->json(200, ['status' => 'ok']);
        }

        try {
            $this->authenticate($headers, $body);
        } catch (SignatureException) {
            return $this->json(401, ['status' => 'error', 'message' => 'Invalid signature.']);
        }

        return $this->json(200, [
            'status' => 'ok',
            'plugin' => $this->manifest->toPublicArray(),
            'handled_hooks' => $this->dispatcher->registeredHooks(),
            'php' => PHP_VERSION,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): RuntimeResult
    {
        return new RuntimeResult($status, Json::encode($body), ['Content-Type' => 'application/json']);
    }

    /**
     * getallheaders() is absent under some SAPIs, so fall back to $_SERVER.
     *
     * @return array<string, string>
     */
    public static function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                return array_change_key_case(array_map('strval', $headers));
            }
        }

        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }
}
