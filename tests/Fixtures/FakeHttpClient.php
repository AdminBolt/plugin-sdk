<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Fixtures;

use AdminBolt\Plugin\Exception\TransportException;
use AdminBolt\Plugin\Http\HttpClient;
use AdminBolt\Plugin\Http\HttpResponse;

/**
 * Replays queued responses and records what was sent, so the API client can
 * be tested without a panel.
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

    public function queueJson(int $status, array $body): self
    {
        return $this->queue(new HttpResponse($status, json_encode($body, JSON_THROW_ON_ERROR)));
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->sent[] = compact('method', 'url', 'headers', 'body');

        $next = array_shift($this->queue);

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
        return $this->sent[array_key_last($this->sent)];
    }
}
