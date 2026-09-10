<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Exception;

/**
 * plugin.json is absent, malformed, or fails validation.
 */
class ManifestException extends PluginException
{
    /** @param list<string> $errors */
    public static function invalid(string $path, array $errors): self
    {
        return new self(sprintf(
            "Manifest \"%s\" is invalid:\n  - %s",
            $path,
            implode("\n  - ", $errors)
        ));
    }

    public static function notFound(string $path): self
    {
        return new self(sprintf('Manifest "%s" not found.', $path));
    }
}
