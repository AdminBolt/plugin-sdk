<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Logging;

/**
 * A deliberately small logging contract.
 *
 * Method names and the (level, message, context) signature match PSR-3, so
 * {@see PsrLogger} can forward to a Monolog instance a plugin already has,
 * but the SDK does not require psr/log: a plugin must install with nothing
 * but ext-curl and ext-json.
 */
interface Logger
{
    public function log(string $level, string $message, array $context = []): void;

    public function debug(string $message, array $context = []): void;

    public function info(string $message, array $context = []): void;

    public function warning(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;
}
