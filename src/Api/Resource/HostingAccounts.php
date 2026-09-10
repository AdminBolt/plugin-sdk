<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

use AdminBolt\Plugin\Support\Arr;

/**
 * /api/hosting-accounts — admin API only.
 *
 * Creating, deleting and suspending an account are admin-key operations; a
 * client key cannot reach any of them.
 */
final class HostingAccounts extends Resource
{
    protected function path(): string
    {
        return 'hosting-accounts';
    }

    /** @return array<mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->firstWhere('username', $username);
    }

    /** @return array<mixed> */
    public function suspend(int|string $id, ?string $reason = null): array
    {
        return $this->client->post($this->path() . '/' . rawurlencode((string) $id) . '/suspend', Arr::withoutNulls([
            'reason' => $reason,
        ]));
    }

    /** @return array<mixed> */
    public function unsuspend(int|string $id): array
    {
        return $this->client->post($this->path() . '/' . rawurlencode((string) $id) . '/unsuspend');
    }

    /** @return array<mixed> */
    public function changePassword(int|string $id, string $password): array
    {
        return $this->client->post($this->path() . '/' . rawurlencode((string) $id) . '/change-password', [
            'password' => $password,
        ]);
    }

    /** @return array<mixed> */
    public function usageDetails(int|string $id): array
    {
        return $this->client->get($this->path() . '/' . rawurlencode((string) $id) . '/usage-details');
    }

    /** @return array<mixed> */
    public function visitorStats(int|string $id): array
    {
        return $this->client->get($this->path() . '/' . rawurlencode((string) $id) . '/visitor-stats');
    }

    /**
     * @param  array<string, mixed> $options
     * @return array<mixed>
     */
    public function configurePhp(int|string $id, array $options): array
    {
        return $this->client->post($this->path() . '/' . rawurlencode((string) $id) . '/configure-php', $options);
    }

    /**
     * A single-use SSO URL for this account's client area. Hand it straight
     * to a browser; it expires quickly and cannot be reused.
     *
     * @return array<mixed>
     */
    public function generateSsoToken(int|string $id): array
    {
        return $this->client->post($this->path() . '/' . rawurlencode((string) $id) . '/generate-sso-token');
    }

    /** @return array<mixed> */
    public function export(int|string $id, array $options = []): array
    {
        return $this->client->post($this->path() . '/' . rawurlencode((string) $id) . '/export', $options);
    }
}
