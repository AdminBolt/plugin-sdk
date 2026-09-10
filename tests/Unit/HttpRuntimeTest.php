<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;
use AdminBolt\Plugin\Hook\Signature;
use AdminBolt\Plugin\Plugin;
use AdminBolt\Plugin\Tests\TestCase;

final class HttpRuntimeTest extends TestCase
{
    public function test_a_blocking_handler_can_veto_the_operation(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());
        $plugin->on(
            Hook::DOMAIN_CREATING,
            fn (HookRequest $r) => HookResponse::reject('example.test is reserved.')
        );

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'example.test']);
        $result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

        // 200, not 4xx: a veto is a successful delivery of a negative answer.
        // A 4xx would be indistinguishable from a broken listener.
        self::assertSame(200, $result->status);
        self::assertSame('reject', $result->json()['status']);
        self::assertSame('example.test is reserved.', $result->json()['message']);
    }

    public function test_a_handler_can_adjust_an_allow_listed_input(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());
        $plugin->on(Hook::DOMAIN_CREATING, fn () => HookResponse::mutate(['php_version' => '8.3']));

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'example.com']);
        $result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

        self::assertSame(['php_version' => '8.3'], $result->json()['mutations']);
    }

    public function test_an_unsigned_delivery_never_reaches_a_handler(): void
    {
        $reached = false;
        $plugin = Plugin::create($this->manifest(), $this->config());
        $plugin->on(Hook::DOMAIN_CREATING, function () use (&$reached) {
            $reached = true;

            return HookResponse::ok();
        });

        [, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'example.com']);
        $result = $plugin->httpRuntime()->handle('POST', '/', [], $body);

        self::assertSame(401, $result->status);
        self::assertFalse($reached, 'A handler ran for a delivery that was never authenticated.');
    }

    public function test_a_forged_signature_is_refused(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());
        $plugin->on(Hook::DOMAIN_CREATING, fn () => HookResponse::ok());

        [$headers, $body] = $this->delivery(
            Hook::DOMAIN_CREATING,
            ['domain' => 'example.com'],
            secret: 'not-the-real-secret'
        );

        self::assertSame(401, $plugin->httpRuntime()->handle('POST', '/', $headers, $body)->status);
    }

    /**
     * The panel may deliver a hook the plugin declared in an earlier version.
     * Acknowledging is correct; erroring would put the plugin into the
     * failing state and, for a blocking hook, could stop the operation.
     */
    public function test_an_unhandled_hook_is_acknowledged(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());

        [$headers, $body] = $this->delivery(Hook::ACCOUNT_SUSPENDED, ['id' => 7]);
        $result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

        self::assertSame(200, $result->status);
        self::assertSame('ok', $result->json()['status']);
    }

    public function test_a_handler_that_throws_becomes_an_error_response(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());
        $plugin->on(Hook::DOMAIN_CREATING, fn () => throw new \RuntimeException('upstream is down'));

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'example.com']);
        $result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

        self::assertSame(200, $result->status);
        self::assertSame('error', $result->json()['status']);
        self::assertStringContainsString('upstream is down', $result->json()['message']);
    }

    public function test_a_malformed_envelope_is_a_bad_request(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());
        $body = 'not json at all';
        $timestamp = time();

        $result = $plugin->httpRuntime()->handle('POST', '/', [
            Signature::HEADER_SIGNATURE => Signature::compute(self::HOOK_SECRET, $timestamp, $body),
            Signature::HEADER_TIMESTAMP => (string) $timestamp,
        ], $body);

        self::assertSame(400, $result->status);
    }

    public function test_an_unsigned_health_probe_reveals_nothing(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());
        $result = $plugin->httpRuntime()->handle('GET', '/health', [], '');

        self::assertSame(200, $result->status);
        self::assertSame(['status' => 'ok'], $result->json());
    }

    public function test_a_signed_health_probe_reports_the_installed_version(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());
        $plugin->on(Hook::DOMAIN_CREATING, fn () => HookResponse::ok());

        $timestamp = time();
        $result = $plugin->httpRuntime()->handle('GET', '/health', [
            Signature::HEADER_SIGNATURE => Signature::compute(self::HOOK_SECRET, $timestamp, ''),
            Signature::HEADER_TIMESTAMP => (string) $timestamp,
        ], '');

        self::assertSame('1.0.0', $result->json()['plugin']['version']);
        self::assertSame([Hook::DOMAIN_CREATING], $result->json()['handled_hooks']);
    }

    public function test_only_post_and_get_are_accepted(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());

        self::assertSame(405, $plugin->httpRuntime()->handle('DELETE', '/', [], '')->status);
    }
}
