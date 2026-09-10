<?php

declare(strict_types=1);

namespace AdminBolt\Plugin;

use AdminBolt\Plugin\Exception\ManifestException;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Support\Arr;
use AdminBolt\Plugin\Support\Json;

/**
 * plugin.json, parsed and validated.
 *
 * The manifest is the only thing the panel reads before it trusts a plugin,
 * so validation happens here rather than in the panel: the CLI runs the same
 * code at package time and the author sees the error before shipping.
 */
final class Manifest
{
    public const FILENAME = 'plugin.json';

    /** Wire format version. Bumped only for a breaking manifest change. */
    public const CONTRACT = 1;

    private const ID_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /**
     * @param array<mixed> $raw
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly array $raw,
        public readonly ?string $path = null,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw ManifestException::notFound($path);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw ManifestException::notFound($path);
        }

        return self::fromArray(Json::decode($contents, self::FILENAME), $path);
    }

    /**
     * Finds plugin.json by walking up from a starting directory, so an
     * entrypoint in public/ or bin/ does not have to hardcode "../".
     */
    public static function discover(string $startDirectory): self
    {
        $directory = realpath($startDirectory) ?: $startDirectory;

        for ($depth = 0; $depth < 6; $depth++) {
            $candidate = $directory . '/' . self::FILENAME;

            if (is_file($candidate)) {
                return self::fromFile($candidate);
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        throw ManifestException::notFound($startDirectory . '/' . self::FILENAME);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, ?string $path = null): self
    {
        $errors = self::validate($data);

        if ($errors !== []) {
            throw ManifestException::invalid($path ?? self::FILENAME, $errors);
        }

        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            version: (string) $data['version'],
            raw: $data,
            path: $path,
        );
    }

    /**
     * Every problem at once, rather than the first: an author fixing a
     * manifest wants the whole list.
     *
     * @param  array<mixed> $data
     * @return list<string>
     */
    public static function validate(array $data): array
    {
        $errors = [];

        foreach (['id', 'name', 'version'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
                $errors[] = sprintf('"%s" is required and must be a non-empty string.', $key);
            }
        }

        if (isset($data['id']) && is_string($data['id']) && preg_match(self::ID_PATTERN, $data['id']) !== 1) {
            $errors[] = '"id" must be lower-case kebab-case, for example "cloudflare-dns". '
                . 'It becomes the plugin directory name, the API key label and the hook route, so it cannot contain spaces, dots or slashes.';
        }

        if (isset($data['version']) && is_string($data['version'])
            && preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?$/', $data['version']) !== 1) {
            $errors[] = '"version" must be a semantic version such as "1.0.0"; the panel compares it to decide whether an update is available.';
        }

        $entrypoint = Arr::get($data, 'runtime.entrypoint');

        if (!is_string($entrypoint) || $entrypoint === '') {
            $errors[] = '"runtime.entrypoint" is required: the PHP file the panel serves or executes, relative to the plugin root.';
        } elseif (str_contains($entrypoint, '..')) {
            $errors[] = '"runtime.entrypoint" must stay inside the plugin directory.';
        }

        $transport = Arr::get($data, 'runtime.transport', 'http');

        if (!in_array($transport, ['http', 'cli'], true)) {
            $errors[] = '"runtime.transport" must be "http" (the panel POSTs to a listener) or "cli" (the panel executes the entrypoint per delivery).';
        }

        $errors = [...$errors, ...self::validateHooks(Arr::get($data, 'hooks', []))];
        $errors = [...$errors, ...self::validateSettings(Arr::get($data, 'settings', []))];
        $errors = [...$errors, ...self::validateScopes(Arr::get($data, 'api.scopes', []))];

        return $errors;
    }

    /** @return list<string> */
    private static function validateHooks(mixed $hooks): array
    {
        if ($hooks === []) {
            return [];
        }

        if (!is_array($hooks)) {
            return ['"hooks" must be an array of hook subscriptions.'];
        }

        $errors = [];
        $seen = [];

        foreach ($hooks as $index => $hook) {
            $label = sprintf('hooks[%s]', (string) $index);

            if (is_string($hook)) {
                $hook = ['event' => $hook];
            }

            if (!is_array($hook) || !isset($hook['event']) || !is_string($hook['event'])) {
                $errors[] = $label . ' must be a hook name, or an object with an "event" key.';
                continue;
            }

            $event = $hook['event'];

            if (!Hook::isKnown($event)) {
                $errors[] = $label . ' subscribes to "' . $event . '", which the panel does not dispatch. ' . self::hint($event);
            }

            if (isset($seen[$event])) {
                $errors[] = sprintf('%s subscribes to "%s" twice; one subscription per hook.', $label, $event);
            }

            $seen[$event] = true;

            if (isset($hook['blocking']) && !is_bool($hook['blocking'])) {
                $errors[] = $label . '.blocking must be true or false.';
            }

            if (($hook['blocking'] ?? false) === true && !Hook::isBlockable($event)) {
                $errors[] = sprintf(
                    '%s marks "%s" as blocking, but that hook reports something that already happened. Only these run inside the operation and can veto it: %s.',
                    $label,
                    $event,
                    implode(', ', Hook::blockable())
                );
            }

            $timeout = $hook['timeout'] ?? null;

            if ($timeout !== null && (!is_int($timeout) || $timeout < 1 || $timeout > 30)) {
                $errors[] = $label . '.timeout must be an integer between 1 and 30 seconds. '
                    . 'A blocking hook holds up a user-facing operation, so the panel caps it.';
            }
        }

        return $errors;
    }

    /**
     * The most useful thing to say about a hook name that is not real: the
     * nearest match, rather than all 27 names.
     */
    private static function hint(string $event): string
    {
        $closest = Hook::closest($event);

        if ($closest !== null) {
            return sprintf('Did you mean "%s"?', $closest);
        }

        return sprintf('Known hooks: %s.', implode(', ', Hook::all()));
    }

    /** @return list<string> */
    private static function validateSettings(mixed $settings): array
    {
        if ($settings === []) {
            return [];
        }

        if (!is_array($settings)) {
            return ['"settings" must be an array of setting definitions.'];
        }

        $types = ['string', 'secret', 'bool', 'int', 'select', 'text', 'url'];
        $errors = [];

        foreach ($settings as $index => $setting) {
            $label = sprintf('settings[%s]', (string) $index);

            if (!is_array($setting)) {
                $errors[] = $label . ' must be an object.';
                continue;
            }

            if (!isset($setting['key']) || !is_string($setting['key']) || trim($setting['key']) === '') {
                $errors[] = $label . '.key is required.';
            }

            $type = $setting['type'] ?? 'string';

            if (!in_array($type, $types, true)) {
                $errors[] = sprintf('%s.type must be one of: %s.', $label, implode(', ', $types));
            }

            if ($type === 'select' && !is_array($setting['options'] ?? null)) {
                $errors[] = $label . '.options is required for a select setting.';
            }

            if ($type === 'secret' && array_key_exists('default', $setting)) {
                $errors[] = $label . ' is a secret and must not carry a default value.';
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private static function validateScopes(mixed $scopes): array
    {
        if ($scopes === []) {
            return [];
        }

        if (!is_array($scopes)) {
            return ['"api.scopes" must be an array of scope strings.'];
        }

        $errors = [];

        foreach ($scopes as $scope) {
            if (!is_string($scope) || preg_match('/^(admin|client|reseller):[a-z0-9\-\/*]+:(read|write)$/', $scope) !== 1) {
                $errors[] = sprintf(
                    'Scope "%s" is malformed. Use "<api>:<resource>:<read|write>", for example "client:dns-records:write".',
                    is_string($scope) ? $scope : get_debug_type($scope)
                );
            }
        }

        return $errors;
    }

    /**
     * Hook subscriptions, normalised to objects with defaults applied.
     *
     * @return list<array{event: string, blocking: bool, timeout: int}>
     */
    public function hooks(): array
    {
        $hooks = $this->raw['hooks'] ?? [];
        $normalised = [];

        foreach (is_array($hooks) ? $hooks : [] as $hook) {
            if (is_string($hook)) {
                $hook = ['event' => $hook];
            }

            if (!is_array($hook) || !isset($hook['event'])) {
                continue;
            }

            $normalised[] = [
                'event' => (string) $hook['event'],
                'blocking' => (bool) ($hook['blocking'] ?? false),
                'timeout' => (int) ($hook['timeout'] ?? 5),
            ];
        }

        return $normalised;
    }

    /** @return list<string> */
    public function hookNames(): array
    {
        return array_map(static fn (array $hook): string => $hook['event'], $this->hooks());
    }

    public function subscribesTo(string $hook): bool
    {
        return in_array($hook, $this->hookNames(), true);
    }

    /** @return list<string> */
    public function scopes(): array
    {
        $scopes = Arr::get($this->raw, 'api.scopes', []);

        return is_array($scopes) ? array_values(array_map('strval', $scopes)) : [];
    }

    public function entrypoint(): string
    {
        return (string) Arr::get($this->raw, 'runtime.entrypoint', 'public/index.php');
    }

    public function transport(): string
    {
        return (string) Arr::get($this->raw, 'runtime.transport', 'http');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->raw, $key, $default);
    }

    /**
     * What the plugin reports to the panel's health probe. Contains nothing
     * secret: it is served before the request is authenticated.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'contract' => self::CONTRACT,
            'hooks' => $this->hooks(),
            'transport' => $this->transport(),
        ];
    }
}
