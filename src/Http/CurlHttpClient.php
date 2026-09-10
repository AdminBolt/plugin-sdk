<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Http;

use AdminBolt\Plugin\Exception\TransportException;

/**
 * The default transport: ext-curl only, no Guzzle, no Symfony HttpClient.
 *
 * A plugin has to be installable next to any PHP application on the server
 * without dragging a dependency tree into it, so the SDK ships its own.
 */
final class CurlHttpClient implements HttpClient
{
    /**
     * @param bool $verifyTls only turn this off against a panel using a
     *        self-signed certificate on a development box. The plugin's API
     *        secret travels in a header on every call.
     */
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        private readonly bool $verifyTls = true,
        private readonly ?string $caBundle = null,
    ) {
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new TransportException('Could not initialise a curl handle.');
        }

        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ];

        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($errorNumber !== 0 || $responseBody === false) {
            throw new TransportException(sprintf(
                '%s %s did not complete: %s (curl error %d).',
                strtoupper($method),
                $url,
                $errorMessage !== '' ? $errorMessage : 'unknown transport failure',
                $errorNumber
            ));
        }

        return new HttpResponse($status, (string) $responseBody, $responseHeaders);
    }

    /**
     * @param  array<string, string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }
}
