<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests;

use AdminBolt\Plugin\Config;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\Signature;
use AdminBolt\Plugin\Manifest;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const HOOK_SECRET = 'test-hook-secret';

    protected function config(array $overrides = []): Config
    {
        return Config::fromArray(array_replace_recursive([
            'panel' => ['url' => 'https://panel.test:2087', 'version' => '1.9.0'],
            'plugin' => ['id' => 'test-plugin', 'install_id' => 'inst_1'],
            'api' => ['key' => 'key-1', 'secret' => 'secret-1'],
            'hooks' => ['secret' => self::HOOK_SECRET, 'tolerance' => 300],
            'settings' => [],
        ], $overrides), 'test fixture');
    }

    protected function manifest(array $overrides = []): Manifest
    {
        return Manifest::fromArray(array_replace_recursive([
            'id' => 'test-plugin',
            'name' => 'Test Plugin',
            'version' => '1.0.0',
            'runtime' => ['entrypoint' => 'public/index.php', 'transport' => 'http'],
            'hooks' => [
                ['event' => Hook::DOMAIN_CREATING, 'blocking' => true],
                ['event' => Hook::DOMAIN_CREATED],
            ],
        ], $overrides));
    }

    /** @return array{0: array<string, string>, 1: string} headers and body */
    protected function delivery(string $hook, array $payload = [], array $context = [], ?int $timestamp = null, ?string $secret = null): array
    {
        $body = json_encode([
            'hook' => $hook,
            'delivery_id' => 'dlv_test',
            'blocking' => Hook::isBlockable($hook),
            'occurred_at' => '2026-09-10T10:00:00+00:00',
            'actor' => ['type' => 'client', 'id' => 7, 'username' => 'acme'],
            'panel' => ['version' => '1.9.0'],
            'context' => $context,
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $timestamp ??= time();

        return [
            [
                Signature::HEADER_SIGNATURE => Signature::compute($secret ?? self::HOOK_SECRET, $timestamp, $body),
                Signature::HEADER_TIMESTAMP => (string) $timestamp,
                Signature::HEADER_HOOK => $hook,
                'content-type' => 'application/json',
            ],
            $body,
        ];
    }
}
