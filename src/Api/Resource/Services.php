<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * Server services and the PHP versions they can serve. Read-only, and
 * available on both APIs — the client API exposes it because PHP version
 * discovery needs it.
 */
final class Services extends Resource
{
    protected function path(): string
    {
        return 'services';
    }

    public function find(int|string $id): array
    {
        $this->unsupported('find', 'the services endpoint returns the full list only.');
    }

    public function create(array $attributes): array
    {
        $this->unsupported('create', 'services are not managed through the API.');
    }

    public function update(int|string $id, array $attributes): array
    {
        $this->unsupported('update', 'services are not managed through the API.');
    }

    public function delete(int|string $id): array
    {
        $this->unsupported('delete', 'services are not managed through the API.');
    }
}
