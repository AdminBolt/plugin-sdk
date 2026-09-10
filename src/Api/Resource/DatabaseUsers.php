<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * Database users and their grants.
 */
final class DatabaseUsers extends Resource
{
    public function __construct(
        \AdminBolt\Plugin\Api\ApiClient $client,
        private readonly string $basePath = 'database-users',
    ) {
        parent::__construct($client);
    }

    protected function path(): string
    {
        return $this->basePath;
    }

    /** @return array<mixed> */
    public function assignDatabase(int|string $userId, int|string $databaseId, ?string $privileges = null): array
    {
        $body = ['database_id' => $databaseId];

        if ($privileges !== null) {
            $body['privileges'] = $privileges;
        }

        return $this->client->post($this->path() . '/' . rawurlencode((string) $userId) . '/assign-database', $body);
    }

    /**
     * Revokes the grant without dropping the user.
     *
     * @return array<mixed>
     */
    public function unassignDatabase(int|string $userId, int|string $databaseId): array
    {
        return $this->client->delete(sprintf(
            '%s/%s/databases/%s',
            $this->path(),
            rawurlencode((string) $userId),
            rawurlencode((string) $databaseId)
        ));
    }

    /** @return array<mixed> */
    public function changePassword(int|string $userId, string $password): array
    {
        return $this->client->post($this->path() . '/' . rawurlencode((string) $userId) . '/change-password', [
            'password' => $password,
        ]);
    }
}
