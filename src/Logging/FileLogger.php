<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Logging;

use AdminBolt\Plugin\Support\Json;

/**
 * Line-per-event JSON to a file the panel can tail in the plugin's log view.
 *
 * Writes are append-only with a single write() call per line, which is atomic
 * for the sizes involved, so concurrent hook deliveries do not interleave.
 * Logging never throws: a plugin must not fail an operation because its log
 * directory filled up.
 */
final class FileLogger implements Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(
        private readonly string $path,
        private readonly string $minimumLevel = 'info',
    ) {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$this->minimumLevel] ?? 1)) {
            return;
        }

        $line = Json::encode([
            'time' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]) . "\n";

        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0o750, true);
        }

        $handle = @fopen($this->path, 'ab');

        if ($handle === false) {
            return;
        }

        @fwrite($handle, $line);
        @fclose($handle);
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
