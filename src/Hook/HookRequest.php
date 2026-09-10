<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Hook;

use AdminBolt\Plugin\Exception\PluginException;
use AdminBolt\Plugin\Support\Arr;
use AdminBolt\Plugin\Support\Json;

/**
 * One hook delivery from the panel, already authenticated.
 *
 * A HookRequest only ever exists for a delivery whose signature verified, so
 * a handler receiving one does not need to check anything about its origin.
 */
final class HookRequest
{
    /**
     * @param array<string, mixed> $payload the operation's own data
     * @param array<string, mixed> $context surrounding objects: hosting
     *        account, reseller, domain, depending on the hook
     * @param array<string, mixed> $actor who triggered it
     * @param array<string, mixed> $panel which panel sent it
     */
    private function __construct(
        public readonly string $hook,
        public readonly string $deliveryId,
        public readonly bool $blocking,
        public readonly array $payload,
        public readonly array $context,
        public readonly array $actor,
        public readonly array $panel,
        public readonly string $occurredAt,
        public readonly string $rawBody,
        public readonly int $attempt = 1,
    ) {
    }

    /**
     * @param array<mixed> $envelope
     */
    public static function fromArray(array $envelope, string $rawBody = ''): self
    {
        $hook = $envelope['hook'] ?? null;

        if (!is_string($hook) || $hook === '') {
            throw new PluginException('Hook delivery envelope has no "hook" key.');
        }

        return new self(
            hook: $hook,
            deliveryId: (string) ($envelope['delivery_id'] ?? ''),
            blocking: (bool) ($envelope['blocking'] ?? Hook::isBlockable($hook)),
            payload: self::arrayValue($envelope, 'payload'),
            context: self::arrayValue($envelope, 'context'),
            actor: self::arrayValue($envelope, 'actor'),
            panel: self::arrayValue($envelope, 'panel'),
            occurredAt: (string) ($envelope['occurred_at'] ?? ''),
            rawBody: $rawBody,
            attempt: (int) ($envelope['attempt'] ?? 1),
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(Json::decode($json, 'hook envelope'), $json);
    }

    /**
     * Dot notation: payload('domain.name'), payload('php_version', '8.2').
     */
    public function payload(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->payload, $key, $default);
    }

    public function context(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->context, $key, $default);
    }

    /**
     * The hosting account the operation belongs to, when the hook has one.
     * Present for every domain, email, database and DNS hook.
     */
    public function hostingAccountId(): ?int
    {
        $id = $this->context('hosting_account.id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function hostingAccountUsername(): ?string
    {
        $username = $this->context('hosting_account.username');

        return is_string($username) && $username !== '' ? $username : null;
    }

    /**
     * Whether this delivery is running inside the operation, and so can still
     * stop it.
     */
    public function isBlocking(): bool
    {
        return Hook::isBlockable($this->hook);
    }

    /**
     * Whether this delivery reports something that already happened, and so
     * cannot be refused.
     */
    public function isNotification(): bool
    {
        return !Hook::isBlockable($this->hook) && !Hook::isLifecycle($this->hook);
    }

    /**
     * The resource this hook is about: "domain" for domain.created.
     */
    public function resource(): string
    {
        return Hook::resource($this->hook);
    }

    /**
     * True when the panel has delivered this before and did not get a usable
     * answer. Handlers with side effects should key on the delivery id and
     * make themselves idempotent rather than trusting this alone.
     */
    public function isRetry(): bool
    {
        return $this->attempt > 1;
    }

    public function actorType(): string
    {
        $type = $this->actor['type'] ?? 'system';

        return is_string($type) ? $type : 'system';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'hook' => $this->hook,
            'delivery_id' => $this->deliveryId,
            'blocking' => $this->blocking,
            'occurred_at' => $this->occurredAt,
            'attempt' => $this->attempt,
            'actor' => $this->actor,
            'panel' => $this->panel,
            'context' => $this->context,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<mixed> $source
     * @return array<string, mixed>
     */
    private static function arrayValue(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
