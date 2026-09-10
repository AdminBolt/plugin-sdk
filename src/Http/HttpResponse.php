<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Http;

use AdminBolt\Plugin\Support\Json;

/**
 * A raw HTTP response. Deliberately not PSR-7: the SDK must install with no
 * dependencies at all, and this carries everything the API client needs.
 */
final class HttpResponse
{
    /** @param array<string, string> $headers header names are lower-cased */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * 429 and 5xx are worth retrying; 4xx is the panel telling the caller the
     * request itself is wrong, and repeating it changes nothing.
     */
    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        return Json::tryDecode($this->body);
    }

    /**
     * Seconds the panel asked the caller to wait, if it said so.
     */
    public function retryAfter(): ?int
    {
        $value = $this->header('retry-after');

        return $value !== null && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }
}
