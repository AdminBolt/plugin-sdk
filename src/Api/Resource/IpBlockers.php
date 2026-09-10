<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * IP blocker rules. No update endpoint: a rule is deleted and re-added.
 */
final class IpBlockers extends Resource
{
    public function __construct(
        \AdminBolt\Plugin\Api\ApiClient $client,
        private readonly string $basePath = 'ip-blockers',
    ) {
        parent::__construct($client);
    }

    protected function path(): string
    {
        return $this->basePath;
    }

    public function update(int|string $id, array $attributes): array
    {
        $this->unsupported('update', 'delete the rule and add the replacement.');
    }
}
