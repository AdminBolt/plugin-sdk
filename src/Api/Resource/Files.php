<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

use AdminBolt\Plugin\Api\ApiClient;
use AdminBolt\Plugin\Exception\ApiException;
use AdminBolt\Plugin\Support\Arr;

/**
 * The account's file system — client API.
 *
 * Every path is relative to the account's home directory and the panel
 * resolves it physically before use, so a plugin cannot walk out of the jail
 * with "..". A plugin should still send paths it built itself rather than
 * ones a visitor typed.
 */
final class Files extends Resource
{
    public function __construct(ApiClient $client)
    {
        parent::__construct($client);
    }

    protected function path(): string
    {
        return 'files';
    }

    public function find(int|string $id): array
    {
        $this->unsupported('find', 'files are addressed by path. Use stat() or read().');
    }

    public function create(array $attributes): array
    {
        $this->unsupported('create', 'use write(), upload() or mkdir().');
    }

    public function update(int|string $id, array $attributes): array
    {
        $this->unsupported('update', 'use write().');
    }

    /** @return array<mixed> */
    public function list(string $path = '/'): array
    {
        return $this->client->get('files', ['path' => $path]);
    }

    /** @return array<mixed> */
    public function stat(string $path): array
    {
        return $this->client->get('files/stat', ['path' => $path]);
    }

    /**
     * The contents of a file, as a string.
     *
     * The panel has served this since the file manager did; the SDK simply
     * never wrapped it, which left a plugin able to write a .env it could not
     * read back.
     */
    public function read(string $path): string
    {
        return $this->client->contents('files/download', ['path' => $path]);
    }

    /**
     * Whether a path exists, without an exception when it does not.
     *
     * A plugin looking for an application asks this about a dozen candidates,
     * and stat() throwing is the wrong shape for a question.
     */
    public function exists(string $path): bool
    {
        try {
            $this->stat($path);

            return true;
        } catch (ApiException $e) {
            if ($e->status === 404 || $e->status === 422 || $e->status === 403) {
                return false;
            }

            throw $e;
        }
    }

    /** @return array<mixed> */
    public function symlink(string $target, string $link): array
    {
        return $this->client->post('files/symlink', [
            'target' => $target,
            'link' => $link,
        ]);
    }

    /**
     * Fetch a URL straight into the account, without it passing through the
     * plugin.
     *
     * @return array<mixed>
     */
    public function fetchUrl(string $url, string $destination): array
    {
        return $this->client->post('files/fetch-url', [
            'url' => $url,
            'destination' => $destination,
        ]);
    }

    /** @return array<mixed> */
    public function write(string $path, string $contents): array
    {
        return $this->client->post('files/write', [
            'path' => $path,
            'content' => $contents,
        ]);
    }

    /** @return array<mixed> */
    public function mkdir(string $path, ?string $mode = null): array
    {
        return $this->client->post('files/mkdir', Arr::withoutNulls([
            'path' => $path,
            'mode' => $mode,
        ]));
    }

    /** @return array<mixed> */
    public function move(string $from, string $to): array
    {
        return $this->client->post('files/move', ['from' => $from, 'to' => $to]);
    }

    /** @return array<mixed> */
    public function copy(string $from, string $to): array
    {
        return $this->client->post('files/copy', ['from' => $from, 'to' => $to]);
    }

    /** @return array<mixed> */
    public function chmod(string $path, string $mode, bool $recursive = false): array
    {
        return $this->client->post('files/chmod', [
            'path' => $path,
            'mode' => $mode,
            'recursive' => $recursive,
        ]);
    }

    /** @return array<mixed> */
    public function archive(string $path, string $destination, string $format = 'zip'): array
    {
        return $this->client->post('files/archive', [
            'path' => $path,
            'destination' => $destination,
            'format' => $format,
        ]);
    }

    /** @return array<mixed> */
    public function extract(string $path, string $destination): array
    {
        return $this->client->post('files/extract', [
            'path' => $path,
            'destination' => $destination,
        ]);
    }

    /** @return array<mixed> */
    public function remove(string $path): array
    {
        return $this->client->delete('files', ['path' => $path]);
    }
}
