<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Exception;

/**
 * A panel REST API call returned a non-2xx status.
 *
 * The decoded error body is kept whole: the panel answers with either
 * {"error": "..."} or {"message": "...", "errors": {...}} depending on the
 * endpoint, and callers occasionally need the validation detail.
 */
class ApiException extends PluginException
{
    /** @param array<string, mixed> $body */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $body = [],
        public readonly ?string $method = null,
        public readonly ?string $path = null,
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(string $method, string $path, int $status, array $body, string $raw): self
    {
        $detail = $body['error']
            ?? $body['message']
            ?? ($raw !== '' ? substr($raw, 0, 300) : 'no response body');

        return new self(
            sprintf('%s %s failed with HTTP %d: %s', $method, $path, $status, (string) $detail),
            $status,
            $body,
            $method,
            $path,
        );
    }

    /**
     * 401/403 from the panel means the plugin's API key is wrong, inactive,
     * IP-blocked, or not scoped to this endpoint. Worth distinguishing:
     * retrying will never help.
     */
    public function isAuthorizationFailure(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    /** @return array<string, list<string>> */
    public function validationErrors(): array
    {
        $errors = $this->body['errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }
}
