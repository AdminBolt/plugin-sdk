<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

use AdminBolt\Plugin\Support\Arr;

/**
 * DNS records, on either API.
 *
 * Records belong to a domain, so every call carries domain_id. SOA and NS
 * records are managed by the panel and are not editable here.
 */
final class DnsRecords extends Resource
{
    public function __construct(
        \AdminBolt\Plugin\Api\ApiClient $client,
        private readonly string $basePath = 'dns-records',
    ) {
        parent::__construct($client);
    }

    protected function path(): string
    {
        return $this->basePath;
    }

    /** @return array<mixed> */
    public function forDomain(int|string $domainId): array
    {
        return $this->all(['domain_id' => $domainId]);
    }

    /** @return array<mixed> */
    public function createRecord(
        int|string $domainId,
        string $type,
        string $name,
        string $content,
        ?int $ttl = null,
        ?int $priority = null,
    ): array {
        return $this->create(Arr::withoutNulls([
            'domain_id' => $domainId,
            'type' => strtoupper($type),
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'priority' => $priority,
        ]));
    }
}
