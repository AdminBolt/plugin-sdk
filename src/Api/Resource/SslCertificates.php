<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * TLS certificates. Keyed by domain id, not by a certificate id: a domain
 * carries at most one certificate, so there is nothing to create and nothing
 * to list per domain.
 */
final class SslCertificates extends Resource
{
    public function __construct(
        \AdminBolt\Plugin\Api\ApiClient $client,
        private readonly string $basePath = 'ssl-certificates',
    ) {
        parent::__construct($client);
    }

    protected function path(): string
    {
        return $this->basePath;
    }

    public function create(array $attributes): array
    {
        $this->unsupported('create', 'issue(), upload() or selfSigned() against a domain id instead.');
    }

    public function update(int|string $id, array $attributes): array
    {
        $this->unsupported('update', 'reissue() replaces a certificate in place.');
    }

    /** @return array<mixed> */
    public function forDomain(int|string $domainId): array
    {
        return $this->find($domainId);
    }

    /**
     * Requests a Let's Encrypt certificate. Issuance is asynchronous: the call
     * returns once the order is queued, not once the certificate is live.
     *
     * @return array<mixed>
     */
    public function issue(int|string $domainId): array
    {
        return $this->client->post($this->path() . '/' . rawurlencode((string) $domainId) . '/issue');
    }

    /** @return array<mixed> */
    public function reissue(int|string $domainId): array
    {
        return $this->client->post($this->path() . '/' . rawurlencode((string) $domainId) . '/reissue');
    }

    /**
     * Installs a certificate the plugin obtained elsewhere.
     *
     * @return array<mixed>
     */
    public function upload(int|string $domainId, string $certificate, string $privateKey, ?string $chain = null): array
    {
        $body = [
            'certificate' => $certificate,
            'private_key' => $privateKey,
        ];

        if ($chain !== null) {
            $body['ca_bundle'] = $chain;
        }

        return $this->client->post($this->path() . '/' . rawurlencode((string) $domainId) . '/upload', $body);
    }

    /** @return array<mixed> */
    public function selfSigned(int|string $domainId): array
    {
        return $this->client->post($this->path() . '/' . rawurlencode((string) $domainId) . '/self-signed');
    }
}
