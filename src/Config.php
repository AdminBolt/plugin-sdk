<?php

declare(strict_types=1);

namespace AdminBolt\Plugin;

use AdminBolt\Plugin\Exception\ConfigurationException;
use AdminBolt\Plugin\Support\Arr;
use AdminBolt\Plugin\Support\Json;

/**
 * Everything the panel hands a plugin at install time.
 *
 * The panel writes runtime.json into the plugin directory, owned by the
 * plugin's system user and mode 0600, and rewrites it whenever the operator
 * changes a setting or the credentials are rotated. A plugin never stores
 * panel credentials of its own, and must not commit them: the file lives
 * outside the plugin's source tree in every packaging layout.
 *
 * For local development, export the BOLT_* environment variables instead and
 * use {@see Config::fromEnvironment()}.
 */
final class Config
{
    public const RUNTIME_FILE = 'runtime.json';

    /**
     * @param array<string, mixed> $settings values for the keys declared under
     *        "settings" in plugin.json, already decrypted by the panel
     * @param array<string, string> $paths writable locations the panel
     *        guarantees exist: "data", "cache", "logs"
     */
    private function __construct(
        public readonly string $panelUrl,
        public readonly string $apiKey,
        public readonly string $apiSecret,
        public readonly string $hookSecret,
        public readonly string $pluginId,
        public readonly array $settings = [],
        public readonly array $paths = [],
        public readonly bool $verifyTls = true,
        public readonly ?string $caBundle = null,
        public readonly int $timeout = 30,
        public readonly int $signatureTolerance = 300,
        public readonly ?string $panelVersion = null,
        public readonly ?string $installId = null,
    ) {
    }

    /**
     * Loads the file the panel wrote, falling back to the environment so the
     * same entrypoint runs both installed and on a developer's machine.
     */
    public static function load(?string $runtimeFile = null): self
    {
        $path = $runtimeFile ?? self::defaultRuntimeFile();

        if ($path !== null && is_file($path)) {
            return self::fromRuntimeFile($path);
        }

        return self::fromEnvironment();
    }

    public static function fromRuntimeFile(string $path): self
    {
        if (!is_file($path)) {
            throw ConfigurationException::missingRuntimeFile($path);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw ConfigurationException::unreadableRuntimeFile($path);
        }

        return self::fromArray(Json::decode($contents, 'runtime file ' . $path), $path);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $source = 'runtime configuration'): self
    {
        $required = static function (string $key) use ($data, $source): string {
            $value = Arr::get($data, $key);

            if (!is_string($value) || $value === '') {
                throw ConfigurationException::missingKey($key, $source);
            }

            return $value;
        };

        $settings = Arr::get($data, 'settings', []);
        $paths = Arr::get($data, 'paths', []);

        return new self(
            panelUrl: rtrim($required('panel.url'), '/'),
            apiKey: $required('api.key'),
            apiSecret: $required('api.secret'),
            hookSecret: $required('hooks.secret'),
            pluginId: $required('plugin.id'),
            settings: is_array($settings) ? $settings : [],
            paths: is_array($paths) ? array_map('strval', $paths) : [],
            verifyTls: (bool) Arr::get($data, 'api.verify_tls', true),
            caBundle: self::nullableString(Arr::get($data, 'api.ca_bundle')),
            timeout: (int) Arr::get($data, 'api.timeout', 30),
            signatureTolerance: (int) Arr::get($data, 'hooks.tolerance', 300),
            panelVersion: self::nullableString(Arr::get($data, 'panel.version')),
            installId: self::nullableString(Arr::get($data, 'plugin.install_id')),
        );
    }

    /**
     * Development and container deployments. BOLT_PLUGIN_SETTINGS carries the
     * same object the runtime file would put under "settings", JSON encoded.
     */
    public static function fromEnvironment(): self
    {
        $env = static function (string $name, ?string $default = null): ?string {
            $value = getenv($name);

            return $value === false || $value === '' ? $default : $value;
        };

        $require = static function (string $name) use ($env): string {
            $value = $env($name);

            if ($value === null) {
                throw ConfigurationException::missingKey($name, 'the environment');
            }

            return $value;
        };

        $settingsJson = $env('BOLT_PLUGIN_SETTINGS', '{}') ?? '{}';

        return new self(
            panelUrl: rtrim($require('BOLT_PANEL_URL'), '/'),
            apiKey: $require('BOLT_API_KEY'),
            apiSecret: $require('BOLT_API_SECRET'),
            hookSecret: $require('BOLT_HOOK_SECRET'),
            pluginId: $env('BOLT_PLUGIN_ID', 'plugin') ?? 'plugin',
            settings: Json::decode($settingsJson, 'BOLT_PLUGIN_SETTINGS'),
            paths: array_filter([
                'data' => $env('BOLT_DATA_DIR'),
                'cache' => $env('BOLT_CACHE_DIR'),
                'logs' => $env('BOLT_LOG_DIR'),
            ]),
            verifyTls: filter_var($env('BOLT_VERIFY_TLS', '1'), FILTER_VALIDATE_BOOL),
            caBundle: $env('BOLT_CA_BUNDLE'),
            timeout: (int) ($env('BOLT_API_TIMEOUT', '30') ?? '30'),
            signatureTolerance: (int) ($env('BOLT_HOOK_TOLERANCE', '300') ?? '300'),
            panelVersion: $env('BOLT_PANEL_VERSION'),
            installId: $env('BOLT_INSTALL_ID'),
        );
    }

    /**
     * A setting declared in plugin.json. Reading an undeclared key returns the
     * default rather than throwing, so adding a setting in a later version
     * does not break an install that has not been reconfigured yet.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings, $key, $default);
    }

    public function requireSetting(string $key): mixed
    {
        $value = $this->setting($key);

        if ($value === null || $value === '') {
            throw ConfigurationException::missingKey('settings.' . $key, 'the plugin configuration');
        }

        return $value;
    }

    /**
     * A writable directory the panel provisions for the plugin. Falls back to
     * the system temp directory so a plugin run outside an install still works.
     */
    public function path(string $name): string
    {
        $path = $this->paths[$name] ?? null;

        if ($path === null) {
            $path = sys_get_temp_dir() . '/bolt-plugin-' . $this->pluginId . '/' . $name;
        }

        return rtrim($path, '/');
    }

    private static function defaultRuntimeFile(): ?string
    {
        $explicit = getenv('BOLT_RUNTIME_FILE');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $pluginDir = getenv('BOLT_PLUGIN_DIR');

        if (is_string($pluginDir) && $pluginDir !== '') {
            return rtrim($pluginDir, '/') . '/' . self::RUNTIME_FILE;
        }

        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Redacts the credentials, so a plugin can dump its configuration into a
     * log or a health response without leaking the key.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'panel_url' => $this->panelUrl,
            'panel_version' => $this->panelVersion,
            'plugin_id' => $this->pluginId,
            'install_id' => $this->installId,
            'api_key' => substr($this->apiKey, 0, 6) . '...',
            'verify_tls' => $this->verifyTls,
            'settings' => array_map(static fn () => '***', $this->settings),
            'paths' => $this->paths,
        ];
    }
}
