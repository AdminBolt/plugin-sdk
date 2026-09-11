<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Testing;

use AdminBolt\Plugin\Exception\TransportException;
use AdminBolt\Plugin\Http\HttpClient;
use AdminBolt\Plugin\Http\HttpResponse;

/**
 * A transport that answers from a queue and records what it was asked.
 *
 * Shipped rather than kept in the SDK's own tests, because every plugin needs
 * exactly this and writing it again in each one is how plugins end up with
 * slightly different ideas of what an HTTP failure looks like.
 *
 *     $http = (new FakeHttpClient())->queueJson(200, ['version' => '11.2.0']);
 *     $plugin = Plugin::create($manifest, $config, http: $http);
 *
 *     $plugin->http()->send('GET', 'https://grafana.test/api/health');
 *
 *     self::assertSame('https://grafana.test/api/health', $http->lastRequest()['url']);
 *
 * It covers both halves of a plugin: the panel calls made through admin() and
 * client(), and whatever the plugin itself calls through http().
 *
 * Queue a TransportException to test what the plugin does when nothing
 * answers at all, which is a different path from a 500 and usually the one
 * with the bug in it.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpResponse|TransportException> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $sent = [];

    public function queue(HttpResponse|TransportException ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    /** @param array<mixed> $body */
    public function queueJson(int $status, array $body): self
    {
        return $this->queue(new HttpResponse($status, json_encode($body, JSON_THROW_ON_ERROR)));
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->sent[] = compact('method', 'url', 'headers', 'body');

        $next = array_shift($this->queue);

        // Louder than returning a 200 with an empty body: a test that made one
        // more call than it queued for is a test whose subject changed, and
        // the assertion that would have caught it is further down.
        if ($next === null) {
            throw new \LogicException(sprintf('No queued response for %s %s.', $method, $url));
        }

        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->sent);
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: ?string} */
    public function lastRequest(): array
    {
        if ($this->sent === []) {
            throw new \LogicException('Nothing has been sent through this client yet.');
        }

        return $this->sent[array_key_last($this->sent)];
    }

    /**
     * Every URL that was asked for, in order. The cheap assertion for "did it
     * make the calls I expected, and did it make them once".
     *
     * @return list<string>
     */
    public function urls(): array
    {
        return array_map(static fn (array $request): string => $request['url'], $this->sent);
    }
}
