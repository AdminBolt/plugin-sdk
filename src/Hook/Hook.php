<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Hook;

/**
 * The catalogue of panel action hooks.
 *
 * Names are "<resource>.<verb>", and the tense of the verb carries the
 * phase:
 *
 *  - A present participle (domain.creating) fires inside the operation,
 *    synchronously, while the panel still holds the request. The plugin can
 *    veto the operation with {@see HookResponse::reject()} or adjust an
 *    allow-listed input with {@see HookResponse::mutate()}. It is on the
 *    user's critical path, so the panel caps the timeout and applies its
 *    failure policy if the plugin is slow or down.
 *
 *  - A past participle (domain.created) fires once the operation has already
 *    succeeded. Delivery is queued and retried, the answer is recorded but
 *    changes nothing, and a rejection is meaningless. Use these for syncing,
 *    billing and audit.
 *
 * Which hooks may block is an explicit list rather than something derived
 * from the name. The naming makes the distinction readable; BLOCKABLE makes
 * it authoritative.
 *
 * A hook name is a stable public API. Renaming one is a breaking change for
 * every installed plugin, so names are added here and never repurposed.
 */
final class Hook
{
    // Domains
    public const DOMAIN_CREATING = 'domain.creating';
    public const DOMAIN_CREATED = 'domain.created';
    public const DOMAIN_DELETING = 'domain.deleting';
    public const DOMAIN_DELETED = 'domain.deleted';
    public const DOMAIN_RENAMED = 'domain.renamed';

    // Hosting accounts
    public const ACCOUNT_CREATING = 'account.creating';
    public const ACCOUNT_CREATED = 'account.created';
    public const ACCOUNT_DELETING = 'account.deleting';
    public const ACCOUNT_DELETED = 'account.deleted';
    public const ACCOUNT_SUSPENDED = 'account.suspended';
    public const ACCOUNT_UNSUSPENDED = 'account.unsuspended';
    public const ACCOUNT_TRANSFERRED = 'account.transferred';

    // DNS
    public const DNS_RECORD_CREATING = 'dns_record.creating';
    public const DNS_RECORD_CREATED = 'dns_record.created';
    public const DNS_RECORD_UPDATED = 'dns_record.updated';
    public const DNS_RECORD_DELETED = 'dns_record.deleted';

    // Email
    public const EMAIL_ACCOUNT_CREATING = 'email_account.creating';
    public const EMAIL_ACCOUNT_CREATED = 'email_account.created';
    public const EMAIL_ACCOUNT_DELETED = 'email_account.deleted';

    // Databases
    public const DATABASE_CREATING = 'database.creating';
    public const DATABASE_CREATED = 'database.created';
    public const DATABASE_DELETED = 'database.deleted';

    // TLS
    public const CERTIFICATE_ISSUED = 'certificate.issued';

    // Server
    public const WEBSERVER_SWITCHED = 'webserver.switched';

    // The plugin's own lifecycle, dispatched by the panel's plugin manager.
    public const PLUGIN_INSTALLED = 'plugin.installed';
    public const PLUGIN_CONFIGURED = 'plugin.configured';
    public const PLUGIN_UNINSTALLED = 'plugin.uninstalled';

    /**
     * Hooks that run inside the operation and can stop it.
     *
     * @var list<string>
     */
    private const BLOCKABLE = [
        self::DOMAIN_CREATING,
        self::DOMAIN_DELETING,
        self::ACCOUNT_CREATING,
        self::ACCOUNT_DELETING,
        self::DNS_RECORD_CREATING,
        self::EMAIL_ACCOUNT_CREATING,
        self::DATABASE_CREATING,
    ];

    /**
     * Payload keys a blocking hook may change, per hook.
     *
     * Anything outside this map is refused by the panel and logged against
     * the plugin, and every accepted mutation is re-validated with the same
     * rules that apply to operator input. A plugin cannot use a mutation to
     * reach a state the panel would not have accepted from a person.
     *
     * @var array<string, list<string>>
     */
    private const MUTABLE_PAYLOAD_KEYS = [
        self::DOMAIN_CREATING => ['php_version', 'document_root'],
        self::EMAIL_ACCOUNT_CREATING => ['quota_mb'],
        self::ACCOUNT_CREATING => ['hosting_plan_id'],
    ];

    /**
     * Pairs a completed hook with the blocking one for the same operation.
     *
     * Exists so tooling can catch the one mistake this naming scheme invites:
     * subscribing to domain.created when you meant domain.creating gives a
     * plugin that validates cleanly and silently cannot veto anything.
     *
     * @var array<string, string>
     */
    private const BLOCKABLE_TWIN = [
        self::DOMAIN_CREATED => self::DOMAIN_CREATING,
        self::DOMAIN_DELETED => self::DOMAIN_DELETING,
        self::ACCOUNT_CREATED => self::ACCOUNT_CREATING,
        self::ACCOUNT_DELETED => self::ACCOUNT_DELETING,
        self::DNS_RECORD_CREATED => self::DNS_RECORD_CREATING,
        self::EMAIL_ACCOUNT_CREATED => self::EMAIL_ACCOUNT_CREATING,
        self::DATABASE_CREATED => self::DATABASE_CREATING,
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

            foreach ((new \ReflectionClass(self::class))->getConstants() as $value) {
                // Array constants (BLOCKABLE, the twin and mutation maps) are
                // metadata about hooks, not hooks.
                if (is_string($value)) {
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
     * Whether this hook runs inside the operation, and so can be declared
     * blocking and can return a rejection.
     */
    public static function isBlockable(string $hook): bool
    {
        return in_array($hook, self::BLOCKABLE, true);
    }

    /**
     * Hooks that run inside the operation and can stop it.
     *
     * @return list<string>
     */
    public static function blockable(): array
    {
        return self::BLOCKABLE;
    }

    /**
     * Hooks that report something that already happened.
     *
     * @return list<string>
     */
    public static function notifications(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (string $hook): bool => !self::isBlockable($hook) && !self::isLifecycle($hook)
        ));
    }

    /**
     * The plugin's own install lifecycle, as opposed to a hosting operation.
     *
     * @return list<string>
     */
    public static function lifecycle(): array
    {
        return [self::PLUGIN_INSTALLED, self::PLUGIN_CONFIGURED, self::PLUGIN_UNINSTALLED];
    }

    public static function isLifecycle(string $hook): bool
    {
        return in_array($hook, self::lifecycle(), true);
    }

    /** @return list<string> */
    public static function mutableKeys(string $hook): array
    {
        return self::MUTABLE_PAYLOAD_KEYS[$hook] ?? [];
    }

    /**
     * The blocking hook for the same operation as this completed one, if
     * there is one. domain.created gives back domain.creating.
     */
    public static function blockableTwin(string $hook): ?string
    {
        return self::BLOCKABLE_TWIN[$hook] ?? null;
    }

    /**
     * The known hook closest to a misspelling, if one is close enough to be
     * worth suggesting.
     *
     * Tooling uses this instead of printing all 27 names at someone who typed
     * "domain.create".
     */
    public static function closest(string $hook): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (self::all() as $candidate) {
            $distance = levenshtein($hook, $candidate);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        // Far enough away and a suggestion is noise rather than help.
        return $bestDistance <= max(3, (int) (strlen($hook) / 3)) ? $best : null;
    }

    /**
     * The resource a hook is about: "domain.creating" gives "domain".
     */
    public static function resource(string $hook): string
    {
        $position = strpos($hook, '.');

        return $position === false ? $hook : substr($hook, 0, $position);
    }
}
