<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Exception;

/**
 * The plugin could not be configured: the runtime file is missing, is not
 * readable, or does not carry the credentials the panel is supposed to write.
 */
class ConfigurationException extends PluginException
{
    public static function missingRuntimeFile(string $path): self
    {
        return new self(sprintf(
            'Runtime file "%s" not found. The panel writes it when the plugin is installed; '
            . 'for local development export BOLT_PANEL_URL, BOLT_API_KEY, BOLT_API_SECRET and BOLT_HOOK_SECRET instead.',
            $path
        ));
    }

    public static function unreadableRuntimeFile(string $path): self
    {
        return new self(sprintf('Runtime file "%s" exists but is not readable by this process.', $path));
    }

    public static function missingKey(string $key, string $source): self
    {
        return new self(sprintf('Required configuration key "%s" is missing from %s.', $key, $source));
    }
}
