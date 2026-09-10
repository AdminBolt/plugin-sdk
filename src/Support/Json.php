<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Support;

use AdminBolt\Plugin\Exception\PluginException;

/**
 * JSON helpers that throw instead of returning false.
 */
final class Json
{
    /** @return array<mixed> */
    public static function decode(string $json, string $what = 'payload'): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PluginException(sprintf('Could not decode %s as JSON: %s', $what, $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new PluginException(sprintf('Expected %s to decode to an array, got %s.', $what, get_debug_type($decoded)));
        }

        return $decoded;
    }

    /**
     * Decodes without throwing, for response bodies that are allowed to be
     * empty or non-JSON (204s, HTML error pages from a proxy in front of the
     * panel).
     *
     * @return array<mixed>
     */
    public static function tryDecode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            return json_encode($value, $flags);
        } catch (\JsonException $e) {
            throw new PluginException('Could not encode value as JSON: ' . $e->getMessage(), 0, $e);
        }
    }
}
