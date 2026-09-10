<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

use AdminBolt\Plugin\Support\Arr;

/**
 * Mailboxes, on either API.
 */
final class EmailAccounts extends Resource
{
    public function __construct(
        \AdminBolt\Plugin\Api\ApiClient $client,
        private readonly string $basePath = 'email-accounts',
    ) {
        parent::__construct($client);
    }

    protected function path(): string
    {
        return $this->basePath;
    }

    /** @return array<mixed> */
    public function createMailbox(int|string $domainId, string $localPart, string $password, ?int $quotaMb = null): array
    {
        return $this->create(Arr::withoutNulls([
            'domain_id' => $domainId,
            'email' => $localPart,
            'password' => $password,
            'quota_mb' => $quotaMb,
        ]));
    }
}
