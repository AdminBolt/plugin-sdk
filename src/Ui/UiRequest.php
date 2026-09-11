<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

use AdminBolt\Plugin\Exception\PluginException;
use AdminBolt\Plugin\Support\Arr;
use AdminBolt\Plugin\Support\Json;

/**
 * Who is looking at a plugin page, and what they just did.
 *
 * The panel signs this the same way it signs a hook delivery, so a
 * UiRequest only ever exists for a request the panel actually made. That
 * matters more here than it does for hooks: the identity in this envelope is
 * what a plugin uses to decide whose data to show.
 *
 * Read the viewer from here and nowhere else. Never take an account id from
 * an action argument or a form field: those travel through the browser and
 * whoever is looking at the page can change them.
 */
final class UiRequest
{
    public const PANEL_ADMIN = 'admin';
    public const PANEL_CLIENT = 'client';
    public const PANEL_RESELLER = 'reseller';

    /**
     * @param array<string, mixed> $viewer the signed-in user
     * @param array<string, mixed> $hostingAccount the account in scope, on
     *        the client panel
     * @param array<string, mixed> $params query parameters, such as the page
     *        number of a paginated table
     * @param array<string, mixed> $input submitted form values, on an action
     * @param array<string, mixed> $arguments values the action carried, such
     *        as which table row it belongs to
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $panel,
        public readonly array $viewer,
        public readonly array $hostingAccount,
        public readonly array $params,
        public readonly ?string $action,
        public readonly array $input,
        public readonly array $arguments,
        public readonly string $locale,
        public readonly string $rawBody,
    ) {
    }

    /** @param array<mixed> $envelope */
    public static function fromArray(array $envelope, string $rawBody = ''): self
    {
        $slug = $envelope['slug'] ?? null;

        if (!is_string($slug) || $slug === '') {
            throw new PluginException('UI request envelope has no "slug".');
        }

        $panel = $envelope['panel'] ?? self::PANEL_ADMIN;

        return new self(
            slug: $slug,
            panel: is_string($panel) ? $panel : self::PANEL_ADMIN,
            viewer: self::arrayValue($envelope, 'viewer'),
            hostingAccount: self::arrayValue($envelope, 'hosting_account'),
            params: self::arrayValue($envelope, 'params'),
            action: isset($envelope['action']) && is_string($envelope['action']) ? $envelope['action'] : null,
            input: self::arrayValue($envelope, 'input'),
            arguments: self::arrayValue($envelope, 'arguments'),
            locale: is_string($envelope['locale'] ?? null) ? $envelope['locale'] : 'en',
            rawBody: $rawBody,
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(Json::decode($json, 'UI request envelope'), $json);
    }

    public function isAction(): bool
    {
        return $this->action !== null;
    }

    /**
     * The hosting account whose page this is, on the client panel.
     *
     * Null on the admin panel, where the page is server-wide and not about
     * one account.
     */
    public function hostingAccountUsername(): ?string
    {
        $username = $this->hostingAccount['username'] ?? null;

        return is_string($username) && $username !== '' ? $username : null;
    }

    /**
     * The panel's signed word for which account this page is about.
     *
     * A plugin's API key is an admin key, so on its own it could name any
     * account on the server, which is not what the approval screen offered.
     * This is what takes that back: the panel mints it for the account whose
     * page it is drawing, and the client API will not act without it.
     *
     * Opaque, short-lived, and bound to this plugin. Pass it along rather
     * than reading it; {@see \AdminBolt\Plugin\Plugin::clientFor()} does.
     */
    public function hostingAccountGrant(): ?string
    {
        $grant = $this->hostingAccount['grant'] ?? null;

        return is_string($grant) && $grant !== '' ? $grant : null;
    }

    public function hostingAccountId(): ?int
    {
        $id = $this->hostingAccount['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function viewerId(): ?int
    {
        $id = $this->viewer['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function viewerName(): ?string
    {
        $name = $this->viewer['name'] ?? $this->viewer['username'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Whether the viewer is an administrator.
     *
     * The panel decides this and signs it. A plugin that gates something on
     * being an admin should ask here rather than inferring it from which page
     * was requested.
     */
    public function isAdmin(): bool
    {
        return $this->panel === self::PANEL_ADMIN;
    }

    /** A submitted form value. */
    public function input(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->input, $key, $default);
    }

    /**
     * A value the action carried, such as the row key of a table action.
     *
     * This made the round trip through the browser. Treat it as a statement
     * of what the viewer is trying to act on, then check they are allowed to.
     */
    public function argument(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->arguments, $key, $default);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->params, $key, $default);
    }

    public function page(): int
    {
        $page = $this->param('page', 1);

        return is_numeric($page) && (int) $page > 0 ? (int) $page : 1;
    }

    /**
     * @param array<mixed> $source
     * @return array<string, mixed>
     */
    private static function arrayValue(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
