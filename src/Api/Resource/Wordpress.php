<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

use AdminBolt\Plugin\Support\Arr;

/**
 * WordPress installations on the account — client API.
 *
 * Everything is scoped by domain_id, since an installation belongs to a
 * domain.
 */
final class Wordpress extends Resource
{
    protected function path(): string
    {
        return 'wordpress';
    }

    public function find(int|string $id): array
    {
        $this->unsupported('find', 'installations are addressed by domain_id. Use info().');
    }

    public function update(int|string $id, array $attributes): array
    {
        $this->unsupported('update', 'use updateCore(), or the plugin and theme methods.');
    }

    public function delete(int|string $id): array
    {
        $this->unsupported('delete', 'use uninstall() with a domain_id.');
    }

    /** @return array<mixed> */
    public function installations(): array
    {
        return $this->client->get('wordpress');
    }

    /**
     * @param  array<string, mixed> $options
     * @return array<mixed>
     */
    public function install(int|string $domainId, array $options = []): array
    {
        return $this->client->post('wordpress', ['domain_id' => $domainId, ...$options]);
    }

    /** @return array<mixed> */
    public function installStatus(int|string $domainId): array
    {
        return $this->client->get('wordpress/install-status', ['domain_id' => $domainId]);
    }

    /** @return array<mixed> */
    public function uninstall(int|string $domainId): array
    {
        return $this->client->delete('wordpress', ['domain_id' => $domainId]);
    }

    /** @return array<mixed> */
    public function info(int|string $domainId): array
    {
        return $this->client->get('wordpress/info', ['domain_id' => $domainId]);
    }

    /** @return array<mixed> */
    public function updateCore(int|string $domainId): array
    {
        return $this->client->post('wordpress/update-core', ['domain_id' => $domainId]);
    }

    /**
     * A single-use wp-admin login URL.
     *
     * @return array<mixed>
     */
    public function sso(int|string $domainId, ?string $user = null): array
    {
        return $this->client->post('wordpress/sso', Arr::withoutNulls([
            'domain_id' => $domainId,
            'user' => $user,
        ]));
    }

    /** @return array<mixed> */
    public function plugins(int|string $domainId): array
    {
        return $this->client->get('wordpress/plugins', ['domain_id' => $domainId]);
    }

    /** @return array<mixed> */
    public function installPlugin(int|string $domainId, string $slug, bool $activate = true): array
    {
        return $this->client->post('wordpress/plugins', [
            'domain_id' => $domainId,
            'slug' => $slug,
            'activate' => $activate,
        ]);
    }

    /** @return array<mixed> */
    public function activatePlugin(int|string $domainId, string $slug): array
    {
        return $this->client->post('wordpress/plugins/' . rawurlencode($slug) . '/activate', ['domain_id' => $domainId]);
    }

    /** @return array<mixed> */
    public function deactivatePlugin(int|string $domainId, string $slug): array
    {
        return $this->client->post('wordpress/plugins/' . rawurlencode($slug) . '/deactivate', ['domain_id' => $domainId]);
    }

    /** @return array<mixed> */
    public function updatePlugin(int|string $domainId, string $slug): array
    {
        return $this->client->post('wordpress/plugins/' . rawurlencode($slug) . '/update', ['domain_id' => $domainId]);
    }

    /** @return array<mixed> */
    public function deletePlugin(int|string $domainId, string $slug): array
    {
        return $this->client->delete('wordpress/plugins/' . rawurlencode($slug), ['domain_id' => $domainId]);
    }

    /** @return array<mixed> */
    public function themes(int|string $domainId): array
    {
        return $this->client->get('wordpress/themes', ['domain_id' => $domainId]);
    }

    /** @return array<mixed> */
    public function installTheme(int|string $domainId, string $slug, bool $activate = false): array
    {
        return $this->client->post('wordpress/themes', [
            'domain_id' => $domainId,
            'slug' => $slug,
            'activate' => $activate,
        ]);
    }
}
