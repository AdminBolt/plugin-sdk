<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Logging;

/**
 * The default. A plugin that has not asked for logging gets none.
 */
final class NullLogger implements Logger
{
    public function log(string $level, string $message, array $context = []): void
    {
    }

    public function debug(string $message, array $context = []): void
    {
    }

    public function info(string $message, array $context = []): void
    {
    }

    public function warning(string $message, array $context = []): void
    {
    }

    public function error(string $message, array $context = []): void
    {
    }
}
