<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Runtime;

/**
 * A response the runtime produced but has not sent yet.
 *
 * Keeping it separate from echoing means the whole delivery path can be
 * tested without a web server: build headers and a body, call handle(), and
 * assert on what comes back.
 */
final class RuntimeResult
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = ['Content-Type' => 'application/json'],
    ) {
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Sends the response. Separate from the constructor so nothing is emitted
     * during testing.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;
    }
}
