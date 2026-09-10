<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Support;

/**
 * The two array helpers the SDK needs, so plugins do not have to pull in
 * illuminate/support to read a nested payload key.
 */
final class Arr
{
    /**
     * Reads "domain.hosting_account.username" out of a nested array.
     *
     * @param array<mixed> $array
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }

        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Drops null values so an optional request field is simply absent rather
     * than sent as null, which several panel endpoints validate differently.
     *
     * @param  array<string, mixed> $array
     * @return array<string, mixed>
     */
    public static function withoutNulls(array $array): array
    {
        return array_filter($array, static fn ($value) => $value !== null);
    }
}
