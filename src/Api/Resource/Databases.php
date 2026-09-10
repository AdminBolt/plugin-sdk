<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * Databases, on either API.
 */
final class Databases extends Resource
{
    public function __construct(
        \AdminBolt\Plugin\Api\ApiClient $client,
        private readonly string $basePath = 'databases',
    ) {
        parent::__construct($client);
    }

    protected function path(): string
    {
        return $this->basePath;
    }

    public function update(int|string $id, array $attributes): array
    {
        $this->unsupported(
            'update',
            'a database has no editable fields on the panel, so there is no update endpoint. Create a new one and drop this.'
        );
    }
}
