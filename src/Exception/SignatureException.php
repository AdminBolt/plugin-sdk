<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Exception;

/**
 * A hook delivery failed authentication.
 *
 * The runtime answers 401 and does NOT run any handler. Never downgrade this
 * to a warning: an unsigned delivery is an unauthenticated caller asking the
 * plugin to act with its panel credentials.
 */
class SignatureException extends PluginException
{
    public static function missingHeader(string $header): self
    {
        return new self(sprintf('Hook delivery is missing the %s header.', $header));
    }

    public static function staleTimestamp(int $timestamp, int $now, int $tolerance): self
    {
        return new self(sprintf(
            'Hook timestamp %d is outside the %d second tolerance window (now %d). '
            . 'Check clock drift between the panel and this host.',
            $timestamp,
            $tolerance,
            $now
        ));
    }

    public static function mismatch(): self
    {
        return new self('Hook signature does not match the configured hook secret.');
    }
}
