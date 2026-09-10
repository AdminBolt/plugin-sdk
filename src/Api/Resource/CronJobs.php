<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * Cron jobs on the caller's account — client API.
 */
final class CronJobs extends Resource
{
    protected function path(): string
    {
        return 'cron-jobs';
    }
}
