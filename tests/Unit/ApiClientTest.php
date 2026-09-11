<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Api\ApiClient;
use AdminBolt\Plugin\Exception\ApiException;
use AdminBolt\Plugin\Exception\TransportException;
use AdminBolt\Plugin\Http\HttpResponse;
use AdminBolt\Plugin\Plugin;
use AdminBolt\Plugin\Ui\UiRequest;
use AdminBolt\Plugin\Testing\FakeHttpClient;
use AdminBolt\Plugin\Tests\TestCase;

final class ApiClientTest extends TestCase
{
    private function client(FakeHttpClient $http, int $maxAttempts = 3): ApiClient
    {
        return new ApiClient('https://panel.test:2087/api', 'key-1', 'secret-1', $http, maxAttempts: $maxAttempts);
    }

    public function test_every_request_carries_the_panels_key_and_secret_headers(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['ok' => true]);

        $this->client($http)->get('hosting-accounts');

        $headers = $http->lastRequest()['headers'];
        self::assertSame('key-1', $headers['X-API-Key']);
        self::assertSame('secret-1', $headers['X-API-Secret']);
    }

    public function test_query_parameters_are_encoded_onto_the_url(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);

        $this->client($http)->get('client/domains', ['domain_id' => 4, 'q' => 'a b']);

        self::assertSame('https://panel.test:2087/api/client/domains?domain_id=4&q=a+b', $http->lastRequest()['url']);
    }

    public function test_a_non_2xx_answer_becomes_an_exception_carrying_the_panels_message(): void
    {
        $http = (new FakeHttpClient())->queueJson(403, ['error' => 'API key does not have access to this endpoint or method']);

        try {
            $this->client($http)->get('hosting-accounts');
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
            self::assertTrue($e->isAuthorizationFailure());
            self::assertStringContainsString('does not have access', $e->getMessage());
        }
    }

    public function test_validation_detail_survives_onto_the_exception(): void
    {
        $http = (new FakeHttpClient())->queueJson(422, [
            'message' => 'The given data was invalid.',
            'errors' => ['domain' => ['The domain field is required.']],
        ]);

        try {
            $this->client($http)->post('client/domains', []);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(['domain' => ['The domain field is required.']], $e->validationErrors());
        }
    }

    public function test_a_read_is_retried_after_a_server_error(): void
    {
        $http = (new FakeHttpClient())
            ->queue(new HttpResponse(503, ''))
            ->queueJson(200, ['ok' => true]);

        self::assertSame(['ok' => true], $this->client($http)->get('health'));
        self::assertSame(2, $http->callCount());
    }

    /**
     * The panel may well have created the domain and only the answer was
     * lost. Replaying would create a second one.
     */
    public function test_a_write_is_not_replayed_after_a_transport_failure(): void
    {
        $http = (new FakeHttpClient())
            ->queue(new TransportException('connection reset'))
            ->queueJson(200, ['id' => 1]);

        $this->expectException(TransportException::class);

        try {
            $this->client($http)->post('client/domains', ['domain' => 'example.com']);
        } finally {
            self::assertSame(1, $http->callCount(), 'The POST was replayed after an ambiguous failure.');
        }
    }

    /**
     * A 429 is refused before the panel does any work, so replaying it
     * repeats nothing.
     */
    public function test_a_throttled_write_is_retried(): void
    {
        $http = (new FakeHttpClient())
            ->queue(new HttpResponse(429, '', ['retry-after' => '0']))
            ->queueJson(201, ['id' => 1]);

        self::assertSame(['id' => 1], $this->client($http)->post('client/domains', ['domain' => 'example.com']));
        self::assertSame(2, $http->callCount());
    }

    public function test_a_client_error_is_never_retried(): void
    {
        $http = (new FakeHttpClient())->queueJson(404, ['message' => 'Domain not found or access denied.']);

        try {
            $this->client($http)->get('client/domains/9999');
        } catch (ApiException) {
            // expected
        }

        self::assertSame(1, $http->callCount());
    }

    public function test_retries_stop_at_the_configured_limit(): void
    {
        $http = (new FakeHttpClient())
            ->queue(new HttpResponse(500, ''), new HttpResponse(500, ''), new HttpResponse(500, ''));

        $this->expectException(ApiException::class);

        try {
            $this->client($http, maxAttempts: 3)->get('health');
        } finally {
            self::assertSame(3, $http->callCount());
        }
    }

    public function test_acting_on_another_account_sends_the_hosting_account_header(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);
        $plugin = Plugin::create($this->manifest(), $this->config(), http: $http);

        $plugin->client('acme')->domains()->all();

        self::assertSame('acme', $http->lastRequest()['headers']['X-Hosting-Account']);
        self::assertSame('https://panel.test:2087/api/client/domains', $http->lastRequest()['url']);
    }

    /**
     * The panel's own word for which account the plugin is acting on. A
     * plugin key may not name an account itself, so a client API call that
     * does not carry this back is refused: the page envelope is where the
     * grant comes from, and clientFor() is what passes it.
     */
    public function test_the_account_grant_travels_from_the_page_envelope_to_the_panel(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);
        $plugin = Plugin::create($this->manifest(), $this->config(), http: $http);

        $request = UiRequest::fromArray([
            'slug' => 'widgets',
            'panel' => 'client',
            'hosting_account' => ['id' => 7, 'username' => 'acme', 'grant' => 'signed-by-the-panel'],
        ]);

        $plugin->clientFor($request)->domains()->all();

        self::assertSame('acme', $http->lastRequest()['headers']['X-Hosting-Account']);
        self::assertSame('signed-by-the-panel', $http->lastRequest()['headers']['X-Plugin-Account-Grant']);
    }

    public function test_naming_an_account_by_hand_sends_no_grant(): void
    {
        // A plugin choosing an account itself is the case the grant exists to
        // refuse, and the panel refuses it. Nothing is invented here to make
        // such a call look authorised.
        $http = (new FakeHttpClient())->queueJson(200, []);
        $plugin = Plugin::create($this->manifest(), $this->config(), http: $http);

        $plugin->client('acme')->domains()->all();

        self::assertArrayNotHasKey('X-Plugin-Account-Grant', $http->lastRequest()['headers']);
    }

    public function test_the_admin_api_addresses_the_unprefixed_routes(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);
        $plugin = Plugin::create($this->manifest(), $this->config(), http: $http);

        $plugin->admin()->hostingAccounts()->all();

        self::assertSame('https://panel.test:2087/api/hosting-accounts', $http->lastRequest()['url']);
        self::assertArrayNotHasKey('X-Hosting-Account', $http->lastRequest()['headers']);
    }
}
