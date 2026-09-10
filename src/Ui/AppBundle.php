<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

use AdminBolt\Plugin\Runtime\RuntimeResult;

/**
 * A built front end that the panel embeds, instead of a page description.
 *
 * The declarative page is the right answer almost every time: the panel draws
 * it with its own components, so it matches the rest of the panel, follows the
 * operator's theme, works on a phone, is translated, and has no markup to
 * escape. This is for the cases the components genuinely cannot express, such
 * as output that streams while a deploy runs.
 *
 * What it serves is a directory of built files. Nothing is generated, nothing
 * is templated, and no request can leave the directory: a path is resolved
 * physically and refused if it lands outside.
 *
 * Anything that is not a file falls back to index.html, because a front end
 * with its own routes gets asked for paths that only exist inside it.
 */
final class AppBundle
{
    /**
     * Types this will serve, and how it labels them.
     *
     * An allow-list rather than a guess. A bundle is a known set of things -
     * scripts, styles, fonts, images - and a plugin serving something else
     * out of the same directory is a plugin doing something unintended.
     */
    private const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8',
    ];

    /** Big enough for a bundled front end, small enough not to be a file host. */
    private const MAX_BYTES = 16 * 1024 * 1024;

    private readonly string $root;

    public function __construct(string $directory)
    {
        $resolved = realpath($directory);

        $this->root = $resolved === false ? rtrim($directory, '/') : $resolved;
    }

    public function exists(): bool
    {
        return is_dir($this->root) && is_file($this->root . '/index.html');
    }

    public function directory(): string
    {
        return $this->root;
    }

    /**
     * Serve one path out of the bundle.
     *
     * @param string $path The path under the page, with no leading slash.
     */
    public function serve(string $path): RuntimeResult
    {
        if (!$this->exists()) {
            return new RuntimeResult(
                500,
                'This plugin\'s interface has not been built.',
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        $file = $this->resolve($path);

        // Not a file, or outside the bundle: hand back index.html so a front
        // end with its own routes works, rather than 404ing on a route only
        // it knows about. A path that escaped the directory never gets here.
        if ($file === null) {
            $file = $this->root . '/index.html';
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $type = self::TYPES[$extension] ?? null;

        if ($type === null) {
            return new RuntimeResult(
                415,
                'This plugin does not serve that kind of file.',
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        $size = (int) @filesize($file);

        if ($size > self::MAX_BYTES) {
            return new RuntimeResult(
                413,
                'That file is too large to serve.',
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            return new RuntimeResult(
                500,
                'That file could not be read.',
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        return new RuntimeResult($size >= 0 ? 200 : 200, $contents, [
            'Content-Type' => $type,
            // The bundle's own files are content-hashed by every build tool
            // worth using, and index.html must never be cached or a deploy
            // leaves browsers on the previous one forever.
            'Cache-Control' => $extension === 'html'
                ? 'no-store'
                : 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * The file a request path names, or null when there is not one.
     *
     * Resolved physically before use, so a symlink inside the bundle cannot
     * point at something outside it and a "../" cannot walk out.
     */
    private function resolve(string $path): ?string
    {
        $path = ltrim(trim($path), '/');

        if ($path === '' || $path === 'index.html') {
            return $this->root . '/index.html';
        }

        if (str_contains($path, "\0")) {
            return null;
        }

        $candidate = realpath($this->root . '/' . $path);

        if ($candidate === false || !is_file($candidate)) {
            return null;
        }

        // The separator matters: /bundle-old must not pass as inside /bundle.
        if (!str_starts_with($candidate, $this->root . '/')) {
            return null;
        }

        return $candidate;
    }
}
