<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * /api/resellers — admin API only.
 */
final class Resellers extends Resource
{
    protected function path(): string
    {
        return 'resellers';
    }
}
