<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Http;

/**
 * Swap the transport in tests, or to route panel calls through a proxy.
 */
interface HttpClient
{
    /**
     * @param  array<string, string> $headers
     * @throws \AdminBolt\Plugin\Exception\TransportException when no response arrives
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
