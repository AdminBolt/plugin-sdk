<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Hook;

/**
 * The catalogue of panel action hooks.
 *
 * Two families, and the difference matters:
 *
 *  - before_* runs inside the operation, synchronously, while the panel still
 *    holds the request. The plugin can veto the operation with
 *    {@see HookResponse::reject()} or adjust an allow-listed input with
 *    {@see HookResponse::mutate()}. It is on the user's critical path, so the
 *    panel caps the timeout and applies its failure policy if the plugin is
 *    slow or down.
 *
 *  - after_* runs once the operation has already succeeded. Delivery is
 *    queued and retried, the return value is recorded but changes nothing,
 *    and a rejection is meaningless. Use it for syncing, billing and audit.
 *
 * A hook name is a stable public API. Renaming one is a breaking change for
 * every installed plugin, so names are added here and never repurposed.
 */
final class Hook
{
    // Domains
    public const BEFORE_DOMAIN_CREATION = 'before_domain_creation';
    public const AFTER_DOMAIN_CREATION = 'after_domain_creation';
    public const BEFORE_DOMAIN_DELETION = 'before_domain_deletion';
    public const AFTER_DOMAIN_DELETION = 'after_domain_deletion';
    public const AFTER_DOMAIN_RENAME = 'after_domain_rename';

    // Hosting accounts
    public const BEFORE_ACCOUNT_CREATION = 'before_account_creation';
    public const AFTER_ACCOUNT_CREATION = 'after_account_creation';
    public const BEFORE_ACCOUNT_DELETION = 'before_account_deletion';
    public const AFTER_ACCOUNT_DELETION = 'after_account_deletion';
    public const AFTER_ACCOUNT_SUSPENSION = 'after_account_suspension';
    public const AFTER_ACCOUNT_UNSUSPENSION = 'after_account_unsuspension';
    public const AFTER_ACCOUNT_OWNER_CHANGE = 'after_account_owner_change';

    // DNS
    public const BEFORE_DNS_RECORD_CREATION = 'before_dns_record_creation';
    public const AFTER_DNS_RECORD_CREATION = 'after_dns_record_creation';
    public const AFTER_DNS_RECORD_UPDATE = 'after_dns_record_update';
    public const AFTER_DNS_RECORD_DELETION = 'after_dns_record_deletion';

    // Email
    public const BEFORE_EMAIL_ACCOUNT_CREATION = 'before_email_account_creation';
    public const AFTER_EMAIL_ACCOUNT_CREATION = 'after_email_account_creation';
    public const AFTER_EMAIL_ACCOUNT_DELETION = 'after_email_account_deletion';

    // Databases
    public const BEFORE_DATABASE_CREATION = 'before_database_creation';
    public const AFTER_DATABASE_CREATION = 'after_database_creation';
    public const AFTER_DATABASE_DELETION = 'after_database_deletion';

    // TLS
    public const AFTER_SSL_CERTIFICATE_ISSUANCE = 'after_ssl_certificate_issuance';

    // Server
    public const AFTER_WEBSERVER_SWITCH = 'after_webserver_switch';

    // The plugin's own lifecycle, dispatched by the panel's plugin manager.
    public const PLUGIN_INSTALLED = 'plugin_installed';
    public const PLUGIN_SETTINGS_UPDATED = 'plugin_settings_updated';
    public const PLUGIN_UNINSTALLED = 'plugin_uninstalled';

    /**
     * Payload keys a before_* hook may change, per hook.
     *
     * Anything outside this map is refused by the panel and logged against the
     * plugin, and every accepted mutation is re-validated with the same rules
     * that apply to operator input. A plugin cannot use a mutation to reach a
     * state the panel would not have accepted from a person.
     *
     * @var array<string, list<string>>
     */
    private const MUTABLE_PAYLOAD_KEYS = [
        self::BEFORE_DOMAIN_CREATION => ['php_version', 'document_root'],
        self::BEFORE_EMAIL_ACCOUNT_CREATION => ['quota_mb'],
        self::BEFORE_ACCOUNT_CREATION => ['hosting_plan_id'],
    ];

    /**
     * Every hook name, read off the constants above so the list cannot drift
     * from the definitions.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        static $all = null;

        if ($all === null) {
            $all = [];

            foreach ((new \ReflectionClass(self::class))->getConstants() as $name => $value) {
                if ($name !== 'MUTABLE_PAYLOAD_KEYS' && is_string($value)) {
                    $all[] = $value;
                }
            }
        }

        return $all;
    }

    public static function isKnown(string $hook): bool
    {
        return in_array($hook, self::all(), true);
    }

    /**
     * Only a before_* hook runs inside the operation, so only a before_* hook
     * can be marked blocking or return a rejection.
     */
    public static function isBlockable(string $hook): bool
    {
        return str_starts_with($hook, 'before_');
    }

    /** @return list<string> */
    public static function mutableKeys(string $hook): array
    {
        return self::MUTABLE_PAYLOAD_KEYS[$hook] ?? [];
    }

    /** @return list<string> */
    public static function before(): array
    {
        return array_values(array_filter(self::all(), static fn (string $h): bool => str_starts_with($h, 'before_')));
    }

    /** @return list<string> */
    public static function after(): array
    {
        return array_values(array_filter(self::all(), static fn (string $h): bool => str_starts_with($h, 'after_')));
    }
}
