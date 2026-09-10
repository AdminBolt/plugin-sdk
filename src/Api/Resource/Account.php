<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * The single account a client API call acts on — client API only.
 *
 * There is no id anywhere in these paths on purpose: the account is derived
 * from the API key, or from the X-Hosting-Account header when an admin key is
 * acting on someone's behalf.
 */
final class Account extends Resource
{
    protected function path(): string
    {
        return 'account';
    }

    public function find(int|string $id): array
    {
        $this->unsupported('find', 'the account is derived from the API key. Use show().');
    }

    public function create(array $attributes): array
    {
        $this->unsupported('create', 'accounts are created through the admin API.');
    }

    public function delete(int|string $id): array
    {
        $this->unsupported('delete', 'accounts are deleted through the admin API.');
    }

    /** @return array<mixed> */
    public function show(): array
    {
        return $this->client->get($this->path());
    }

    /**
     * @param  array<string, mixed> $attributes
     * @return array<mixed>
     */
    public function save(array $attributes): array
    {
        return $this->client->put($this->path(), $attributes);
    }

    /** @return array<mixed> */
    public function phpSettings(): array
    {
        return $this->client->get($this->path() . '/php-settings');
    }

    /**
     * @param  array<string, mixed> $options
     * @return array<mixed>
     */
    public function configurePhp(array $options): array
    {
        return $this->client->post($this->path() . '/configure-php', $options);
    }

    /** @return array<mixed> */
    public function usageDetails(): array
    {
        return $this->client->get($this->path() . '/usage-details');
    }

    /** @return array<mixed> */
    public function visitorStats(): array
    {
        return $this->client->get($this->path() . '/visitor-stats');
    }
}
