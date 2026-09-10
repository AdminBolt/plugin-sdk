<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

/**
 * FTP accounts, on either API.
 */
final class FtpAccounts extends Resource
{
    public function __construct(
        \AdminBolt\Plugin\Api\ApiClient $client,
        private readonly string $basePath = 'ftp-accounts',
    ) {
        parent::__construct($client);
    }

    protected function path(): string
    {
        return $this->basePath;
    }
}
