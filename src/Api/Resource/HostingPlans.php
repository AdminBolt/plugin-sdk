<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * /api/hosting-plans — admin API only.
 */
final class HostingPlans extends Resource
{
    protected function path(): string
    {
        return 'hosting-plans';
    }

    /** @return array<mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->firstWhere('name', $name);
    }
}
