<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Hook;

use AdminBolt\Plugin\Exception\PluginException;

/**
 * What a handler tells the panel to do.
 *
 * The HTTP status answers "did the plugin run" and the body's "status"
 * answers "what did it decide". A rejection is a successful delivery of a
 * negative answer, so it is HTTP 200 with status "reject", never a 4xx: a 4xx
 * is indistinguishable from a broken listener and the panel would apply its
 * delivery failure policy instead of honouring the veto.
 */
final class HookResponse implements \JsonSerializable
{
    public const STATUS_OK = 'ok';
    public const STATUS_REJECT = 'reject';
    public const STATUS_ERROR = 'error';

    /**
     * @param array<string, mixed> $data recorded on the delivery for the
     *        operator to see; never acted on
     * @param array<string, mixed> $mutations allow-listed payload changes
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $data = [],
        public readonly array $mutations = [],
    ) {
    }

    /**
     * The operation may proceed. The only valid answer from a notification hook.
     *
     * @param array<string, mixed> $data
     */
    public static function ok(array $data = [], ?string $message = null): self
    {
        return new self(self::STATUS_OK, $message, $data);
    }

    /**
     * Veto the operation. Only meaningful from a blocking hook.
     *
     * The message is shown to whoever triggered the operation, so write it for
     * them: "example.com is not on the allow list for this reseller" rather
     * than "policy check failed".
     *
     * @param array<string, mixed> $data
     */
    public static function reject(string $message, array $data = []): self
    {
        if (trim($message) === '') {
            throw new PluginException('A rejection must carry a message: the panel shows it to the user whose operation was refused.');
        }

        return new self(self::STATUS_REJECT, $message, $data);
    }

    /**
     * Let the operation proceed with adjusted input.
     *
     * Only keys in {@see Hook::mutableKeys()} for this hook are accepted; the
     * panel refuses anything else and re-validates what it does accept.
     *
     * @param array<string, mixed> $mutations
     * @param array<string, mixed> $data
     */
    public static function mutate(array $mutations, ?string $message = null, array $data = []): self
    {
        if ($mutations === []) {
            return self::ok($data, $message);
        }

        return new self(self::STATUS_OK, $message, $data, $mutations);
    }

    /**
     * The plugin could not decide. The panel applies the failure policy
     * configured for this plugin: fail open (proceed, default) or fail closed
     * (abort). Prefer this over throwing when the failure is expected, such as
     * an upstream API being unreachable.
     */
    public static function error(string $message): self
    {
        return new self(self::STATUS_ERROR, $message);
    }

    public function isRejection(): bool
    {
        return $this->status === self::STATUS_REJECT;
    }

    /**
     * Always 200 for a decision the plugin actually made. The panel reads the
     * body to learn what that decision was.
     */
    public function httpStatus(): int
    {
        return 200;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $body = ['status' => $this->status];

        if ($this->message !== null) {
            $body['message'] = $this->message;
        }

        if ($this->mutations !== []) {
            $body['mutations'] = $this->mutations;
        }

        if ($this->data !== []) {
            $body['data'] = $this->data;
        }

        return $body;
    }
}
