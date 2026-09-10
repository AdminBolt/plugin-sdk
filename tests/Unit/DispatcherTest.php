<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Exception\PluginException;
use AdminBolt\Plugin\Hook\Dispatcher;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookHandler;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;
use AdminBolt\Plugin\Tests\TestCase;

final class DispatcherTest extends TestCase
{
    private function request(string $hook = Hook::DOMAIN_CREATING, array $payload = []): HookRequest
    {
        return HookRequest::fromArray([
            'hook' => $hook,
            'delivery_id' => 'dlv_1',
            'payload' => $payload,
            'context' => ['hosting_account' => ['id' => 7, 'username' => 'acme']],
        ]);
    }

    public function test_the_first_rejection_stops_the_chain(): void
    {
        $secondRan = false;
        $dispatcher = new Dispatcher();

        $dispatcher->on(Hook::DOMAIN_CREATING, fn () => HookResponse::reject('policy says no'));
        $dispatcher->on(Hook::DOMAIN_CREATING, function () use (&$secondRan) {
            $secondRan = true;

            return HookResponse::ok();
        });

        $response = $dispatcher->dispatch($this->request());

        self::assertTrue($response->isRejection());
        self::assertFalse($secondRan, 'A handler ran after the operation was already vetoed.');
    }

    public function test_mutations_from_several_handlers_merge(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->on(Hook::DOMAIN_CREATING, fn () => HookResponse::mutate(['php_version' => '8.3']));
        $dispatcher->on(Hook::DOMAIN_CREATING, fn () => HookResponse::mutate(['document_root' => 'public']));

        $response = $dispatcher->dispatch($this->request());

        self::assertSame(['php_version' => '8.3', 'document_root' => 'public'], $response->mutations);
    }

    public function test_one_failing_handler_does_not_stop_the_others(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->on(Hook::DOMAIN_CREATING, fn () => throw new \RuntimeException('boom'));
        $dispatcher->on(Hook::DOMAIN_CREATING, fn () => HookResponse::mutate(['php_version' => '8.3']));

        $response = $dispatcher->dispatch($this->request());

        self::assertSame(['php_version' => '8.3'], $response->mutations);
    }

    public function test_subscribing_to_an_unknown_hook_fails_loudly(): void
    {
        $this->expectException(PluginException::class);
        $this->expectExceptionMessageMatches('/Unknown hook/');

        (new Dispatcher())->on('domain.combusted', fn () => HookResponse::ok());
    }

    public function test_a_handler_object_subscribes_to_every_hook_it_names(): void
    {
        $handler = new class () implements HookHandler {
            public function hooks(): array
            {
                return [Hook::DOMAIN_CREATING, Hook::DOMAIN_CREATED];
            }

            public function handle(HookRequest $request): HookResponse
            {
                return HookResponse::ok(['hook' => $request->hook]);
            }
        };

        $dispatcher = (new Dispatcher())->register($handler);

        self::assertTrue($dispatcher->handles(Hook::DOMAIN_CREATING));
        self::assertTrue($dispatcher->handles(Hook::DOMAIN_CREATED));
        self::assertSame(
            ['hook' => Hook::DOMAIN_CREATED],
            $dispatcher->dispatch($this->request(Hook::DOMAIN_CREATED))->data
        );
    }

    public function test_an_observer_cannot_change_the_outcome(): void
    {
        $seen = null;
        $dispatcher = new Dispatcher();
        $dispatcher->on(Hook::DOMAIN_CREATING, fn () => HookResponse::reject('no'));
        $dispatcher->observe(function (HookRequest $r, HookResponse $response) use (&$seen) {
            $seen = $response->status;

            return HookResponse::ok();
        });

        self::assertTrue($dispatcher->dispatch($this->request())->isRejection());
        self::assertSame('reject', $seen);
    }

    public function test_a_failing_observer_does_not_break_the_delivery(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->on(Hook::DOMAIN_CREATED, fn () => HookResponse::ok());
        $dispatcher->observe(fn () => throw new \RuntimeException('metrics backend down'));

        self::assertSame('ok', $dispatcher->dispatch($this->request(Hook::DOMAIN_CREATED))->status);
    }
}
