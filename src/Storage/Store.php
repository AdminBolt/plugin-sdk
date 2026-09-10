<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Storage;

use AdminBolt\Plugin\Exception\PluginException;
use AdminBolt\Plugin\Support\Json;

/**
 * Somewhere for a plugin to keep what it knows about one account.
 *
 * Settings are the operator's, one set for the whole plugin. A plugin with
 * pages has the other kind of state: which applications this customer has,
 * what they called them, which one they were last looking at. That is per
 * account, and until now every plugin invented its own place to put it.
 *
 *     $store = $plugin->store($request);
 *     $store->put('apps', $apps);
 *     $store->get('apps', []);
 *
 * A file per account under the data directory the panel guarantees is
 * writable. Small state, read on every page render, written rarely: a file is
 * the right size of answer, and it survives a restart, which an in-process
 * cache would not.
 *
 * This is not a place for secrets. It is not encrypted, and a value a
 * customer should not see should not be here.
 */
final class Store
{
    /** Enough for a list of applications and their settings, not a database. */
    private const MAX_BYTES = 1024 * 1024;

    private ?array $data = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * A store scoped to one account, named so that no account can reach
     * another's by being called something awkward.
     */
    public static function forAccount(string $directory, ?string $account): self
    {
        $name = $account === null || $account === ''
            ? 'plugin'
            : 'account-' . hash('sha256', $account);

        return new self(rtrim($directory, '/') . '/' . $name . '.json');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function put(string $key, mixed $value): void
    {
        $data = $this->all();
        $data[$key] = $value;

        $this->write($data);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function merge(array $values): void
    {
        $this->write([...$this->all(), ...$values]);
    }

    public function forget(string $key): void
    {
        $data = $this->all();

        if (!array_key_exists($key, $data)) {
            return;
        }

        unset($data[$key]);

        $this->write($data);
    }

    public function flush(): void
    {
        $this->data = [];

        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (!is_file($this->file)) {
            return $this->data = [];
        }

        $contents = @file_get_contents($this->file);

        if ($contents === false || $contents === '') {
            return $this->data = [];
        }

        try {
            $decoded = Json::decode($contents);
        } catch (PluginException) {
            // A store that cannot be read is treated as empty rather than
            // fatal. Losing a page's remembered state is a nuisance; a page
            // that will not render because of it is an outage.
            return $this->data = [];
        }

        return $this->data = is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $encoded = Json::encode($data);

        if (strlen($encoded) > self::MAX_BYTES) {
            throw new PluginException('This plugin store holds more than it is meant to.');
        }

        $directory = dirname($this->file);

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new PluginException(sprintf('The plugin data directory %s is not writable.', $directory));
        }

        // Written whole and moved into place, so a reader never sees half a
        // file and a crash mid-write cannot leave the store unreadable.
        $temporary = $this->file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            throw new PluginException(sprintf('Could not write to the plugin store at %s.', $this->file));
        }

        @chmod($temporary, 0o600);

        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);

            throw new PluginException(sprintf('Could not write to the plugin store at %s.', $this->file));
        }

        $this->data = $data;
    }
}
