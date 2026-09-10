<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api;

use AdminBolt\Plugin\Api\Resource\Databases;
use AdminBolt\Plugin\Api\Resource\DatabaseUsers;
use AdminBolt\Plugin\Api\Resource\DnsRecords;
use AdminBolt\Plugin\Api\Resource\Domains;
use AdminBolt\Plugin\Api\Resource\EmailAccounts;
use AdminBolt\Plugin\Api\Resource\FtpAccounts;
use AdminBolt\Plugin\Api\Resource\HostingAccounts;
use AdminBolt\Plugin\Api\Resource\HostingPlans;
use AdminBolt\Plugin\Api\Resource\IpBlockers;
use AdminBolt\Plugin\Api\Resource\Resellers;
use AdminBolt\Plugin\Api\Resource\Services;
use AdminBolt\Plugin\Api\Resource\SslCertificates;

/**
 * The panel's admin REST API, server-wide and unscoped.
 *
 * Reaching it at all requires the plugin's key to have been minted with
 * owner_type "admin", which the panel only does for a plugin whose manifest
 * declares admin scopes and whose install an administrator approved. A plugin
 * that only needs to act on one account should use {@see ClientApi} instead:
 * a narrower key is a smaller blast radius when the plugin has a bug.
 */
final class AdminApi
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    public function hostingAccounts(): HostingAccounts
    {
        return new HostingAccounts($this->client);
    }

    public function hostingPlans(): HostingPlans
    {
        return new HostingPlans($this->client);
    }

    public function resellers(): Resellers
    {
        return new Resellers($this->client);
    }

    public function domains(): Domains
    {
        return new Domains($this->client, 'hosting-account/domains');
    }

    public function dnsRecords(): DnsRecords
    {
        return new DnsRecords($this->client, 'hosting-account/dns-records');
    }

    public function emailAccounts(): EmailAccounts
    {
        return new EmailAccounts($this->client, 'hosting-account/email-accounts');
    }

    public function databases(): Databases
    {
        return new Databases($this->client, 'hosting-account/databases');
    }

    public function databaseUsers(): DatabaseUsers
    {
        return new DatabaseUsers($this->client, 'hosting-account/database-users');
    }

    public function ftpAccounts(): FtpAccounts
    {
        return new FtpAccounts($this->client, 'hosting-account/ftp-accounts');
    }

    public function ipBlockers(): IpBlockers
    {
        return new IpBlockers($this->client, 'hosting-account/ip-blockers');
    }

    public function sslCertificates(): SslCertificates
    {
        return new SslCertificates($this->client, 'hosting-account/ssl-certificates');
    }

    public function services(): Services
    {
        return new Services($this->client);
    }

    /** @return array<mixed> */
    public function statistics(): array
    {
        return $this->client->get('admin/statistics');
    }

    /** @return array<mixed> */
    public function health(): array
    {
        return $this->client->get('health');
    }

    /**
     * Anything without a typed resource yet: system updates, runtimes,
     * migrations. The client signs and retries the call the same way.
     */
    public function raw(): ApiClient
    {
        return $this->client;
    }
}
