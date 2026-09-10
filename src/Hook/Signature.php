<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Hook;

use AdminBolt\Plugin\Exception\SignatureException;

/**
 * HMAC authentication for hook deliveries.
 *
 * The panel signs the exact bytes it sends. The signed string is
 * "v1:{timestamp}:{raw body}", so the timestamp is covered too and a captured
 * delivery cannot be replayed outside the tolerance window.
 *
 * Always verify against the raw request body. Decoding and re-encoding the
 * JSON first will change key order and whitespace, and the signature will
 * never match.
 */
final class Signature
{
    public const VERSION = 'v1';

    public const HEADER_SIGNATURE = 'X-Bolt-Signature';
    public const HEADER_TIMESTAMP = 'X-Bolt-Timestamp';
    public const HEADER_HOOK = 'X-Bolt-Hook';
    public const HEADER_DELIVERY = 'X-Bolt-Delivery';
    public const HEADER_PLUGIN = 'X-Bolt-Plugin';
    public const HEADER_CONTRACT = 'X-Bolt-Contract';

    /** Clock skew allowed between the panel and the plugin host, in seconds. */
    public const DEFAULT_TOLERANCE = 300;

    public static function compute(string $secret, int $timestamp, string $body): string
    {
        return self::VERSION . '=' . hash_hmac(
            'sha256',
            self::VERSION . ':' . $timestamp . ':' . $body,
            $secret
        );
    }

    /**
     * @param string $header the X-Bolt-Signature value. During a secret
     *        rotation the panel sends both signatures, comma separated, so the
     *        plugin keeps working while the new secret propagates.
     *
     * @throws SignatureException when the delivery is not authentic
     */
    public static function verify(
        string $secret,
        string $header,
        int $timestamp,
        string $body,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): void {
        $now ??= time();

        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            throw SignatureException::staleTimestamp($timestamp, $now, $tolerance);
        }

        $expected = self::compute($secret, $timestamp, $body);

        foreach (explode(',', $header) as $candidate) {
            // hash_equals, never ==: a timing-variable comparison here leaks
            // the signature one byte at a time to a caller that can retry.
            if (hash_equals($expected, trim($candidate))) {
                return;
            }
        }

        throw SignatureException::mismatch();
    }

    /**
     * Headers a plugin sends when it calls a panel webhook back, and what the
     * panel's own test-delivery button produces. Exposed so the CLI can sign
     * a fixture delivery with the same code the panel uses.
     *
     * @return array<string, string>
     */
    public static function headers(string $secret, string $hook, string $body, string $deliveryId, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            self::HEADER_HOOK => $hook,
            self::HEADER_DELIVERY => $deliveryId,
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_SIGNATURE => self::compute($secret, $timestamp, $body),
            self::HEADER_CONTRACT => '1',
            'Content-Type' => 'application/json',
        ];
    }
}
