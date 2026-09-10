<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api;

use AdminBolt\Plugin\Api\Resource\Account;
use AdminBolt\Plugin\Api\Resource\CronJobs;
use AdminBolt\Plugin\Api\Resource\Databases;
use AdminBolt\Plugin\Api\Resource\DatabaseUsers;
use AdminBolt\Plugin\Api\Resource\DnsRecords;
use AdminBolt\Plugin\Api\Resource\Domains;
use AdminBolt\Plugin\Api\Resource\EmailAccounts;
use AdminBolt\Plugin\Api\Resource\Files;
use AdminBolt\Plugin\Api\Resource\FtpAccounts;
use AdminBolt\Plugin\Api\Resource\IpBlockers;
use AdminBolt\Plugin\Api\Resource\Services;
use AdminBolt\Plugin\Api\Resource\SslCertificates;
use AdminBolt\Plugin\Api\Resource\Wordpress;

/**
 * The panel's client REST API, scoped to exactly one hosting account.
 *
 * Which account depends on the key. A hosting-account key always acts on its
 * own; an admin or reseller key acts on whichever account
 * {@see ClientApi::forAccount()} names, and a reseller key only on accounts it
 * owns. Every query the panel runs is scoped to that account, so a path
 * traversal or an id from another account comes back 404, not someone else's
 * data.
 */
final class ClientApi
{
    public function __construct(
        private readonly ApiClient $client,
        public readonly ?string $hostingAccount = null,
    ) {
    }

    /**
     * Act on a specific account, by username.
     *
     * Only meaningful for an admin or reseller key: the panel ignores the
     * header for a hosting-account key, which can only ever act on itself.
     */
    public function forAccount(string $username): self
    {
        return new self(
            $this->client->withHeaders(['X-Hosting-Account' => $username]),
            $username,
        );
    }

    public function account(): Account
    {
        return new Account($this->client);
    }

    public function domains(): Domains
    {
        return new Domains($this->client);
    }

    public function dnsRecords(): DnsRecords
    {
        return new DnsRecords($this->client);
    }

    public function emailAccounts(): EmailAccounts
    {
        return new EmailAccounts($this->client);
    }

    public function databases(): Databases
    {
        return new Databases($this->client);
    }

    public function databaseUsers(): DatabaseUsers
    {
        return new DatabaseUsers($this->client);
    }

    public function ftpAccounts(): FtpAccounts
    {
        return new FtpAccounts($this->client);
    }

    public function cronJobs(): CronJobs
    {
        return new CronJobs($this->client);
    }

    public function ipBlockers(): IpBlockers
    {
        return new IpBlockers($this->client);
    }

    public function sslCertificates(): SslCertificates
    {
        return new SslCertificates($this->client);
    }

    public function files(): Files
    {
        return new Files($this->client);
    }

    public function wordpress(): Wordpress
    {
        return new Wordpress($this->client);
    }

    public function services(): Services
    {
        return new Services($this->client);
    }

    /** @return array<mixed> */
    public function errorLogs(array $query = []): array
    {
        return $this->client->get('error-logs', $query);
    }

    /**
     * Anything without a typed resource yet: applications, PostgreSQL remote
     * access, SSO token minting.
     */
    public function raw(): ApiClient
    {
        return $this->client;
    }
}
