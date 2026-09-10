<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * Domains.
 *
 * The admin API exposes them at hosting-account/domains and the client API at
 * client/domains; both speak the same shape, so the path is injected and one
 * class serves both. Creating a domain through this resource fires the same
 * domain.creating and domain.created hooks as the panel UI,
 * including for the plugin that made the call.
 */
final class Domains extends Resource
{
    public function __construct(
        \AdminBolt\Plugin\Api\ApiClient $client,
        private readonly string $basePath = 'domains',
    ) {
        parent::__construct($client);
    }

    protected function path(): string
    {
        return $this->basePath;
    }

    /** @return array<mixed>|null */
    public function findByName(string $domain): ?array
    {
        return $this->firstWhere('domain', $domain);
    }
}
