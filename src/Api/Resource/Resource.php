<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

use AdminBolt\Plugin\Api\ApiClient;
use AdminBolt\Plugin\Exception\PluginException;
use AdminBolt\Plugin\Support\Arr;

/**
 * Base for the panel's REST collections.
 *
 * Endpoints the panel does not implement are overridden in the subclass to
 * say so, rather than left in place to fail with a 405 at runtime.
 */
abstract class Resource
{
    public function __construct(protected readonly ApiClient $client)
    {
    }

    /**
     * Path relative to the API root, without a leading slash.
     */
    abstract protected function path(): string;

    /**
     * @param  array<string, mixed> $query
     * @return array<mixed>
     */
    public function all(array $query = []): array
    {
        return $this->client->get($this->path(), $query);
    }

    /** @return array<mixed> */
    public function find(int|string $id): array
    {
        return $this->client->get($this->path() . '/' . rawurlencode((string) $id));
    }

    /**
     * @param  array<string, mixed> $attributes
     * @return array<mixed>
     */
    public function create(array $attributes): array
    {
        return $this->client->post($this->path(), Arr::withoutNulls($attributes));
    }

    /**
     * @param  array<string, mixed> $attributes
     * @return array<mixed>
     */
    public function update(int|string $id, array $attributes): array
    {
        return $this->client->put($this->path() . '/' . rawurlencode((string) $id), Arr::withoutNulls($attributes));
    }

    /** @return array<mixed> */
    public function delete(int|string $id): array
    {
        return $this->client->delete($this->path() . '/' . rawurlencode((string) $id));
    }

    /**
     * Convenience for the common "find the one matching this field" need,
     * since the panel's index endpoints return plain arrays.
     *
     * @param  array<string, mixed> $query
     * @return array<mixed>|null
     */
    public function firstWhere(string $field, mixed $value, array $query = []): ?array
    {
        foreach ($this->all($query) as $item) {
            if (is_array($item) && Arr::get($item, $field) === $value) {
                return $item;
            }
        }

        return null;
    }

    protected function unsupported(string $operation, string $reason): never
    {
        throw new PluginException(sprintf('%s does not support %s: %s', static::class, $operation, $reason));
    }
}
