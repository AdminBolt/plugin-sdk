<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Logging;

/**
 * Forwards to any PSR-3 logger a plugin already uses, without the SDK
 * depending on psr/log. The wrapped object only has to expose log().
 */
final class PsrLogger implements Logger
{
    public function __construct(private readonly object $psrLogger)
    {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (method_exists($this->psrLogger, 'log')) {
            $this->psrLogger->log($level, $message, $context);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }
}
