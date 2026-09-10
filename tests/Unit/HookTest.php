<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Tests\TestCase;

final class HookTest extends TestCase
{
    public function test_every_hook_is_resource_dot_verb(): void
    {
        foreach (Hook::all() as $hook) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/',
                $hook,
                sprintf('"%s" does not follow <resource>.<verb>.', $hook)
            );
        }
    }

    public function test_the_catalogue_has_no_duplicates(): void
    {
        self::assertSame(Hook::all(), array_values(array_unique(Hook::all())));
    }

    /**
     * Blockability is an explicit list, not something inferred from the name.
     * The tense makes it readable; the list makes it authoritative.
     */
    public function test_blockability_is_not_inferred_from_the_name(): void
    {
        self::assertTrue(Hook::isBlockable(Hook::DOMAIN_CREATING));
        self::assertFalse(Hook::isBlockable(Hook::DOMAIN_CREATED));

        // dns_record.updated is a past participle and not blockable, and
        // account.transferred proves the rule is not "ends in -ing".
        self::assertFalse(Hook::isBlockable(Hook::DNS_RECORD_UPDATED));
        self::assertFalse(Hook::isBlockable(Hook::ACCOUNT_TRANSFERRED));
    }

    public function test_every_blockable_hook_is_a_real_hook(): void
    {
        foreach (Hook::blockable() as $hook) {
            self::assertTrue(Hook::isKnown($hook));
        }
    }

    public function test_only_blockable_hooks_declare_mutable_keys(): void
    {
        foreach (Hook::all() as $hook) {
            if (Hook::mutableKeys($hook) !== []) {
                self::assertTrue(
                    Hook::isBlockable($hook),
                    sprintf('"%s" declares mutable keys but does not run inside the operation.', $hook)
                );
            }
        }
    }

    public function test_the_three_families_partition_the_catalogue(): void
    {
        $union = [...Hook::blockable(), ...Hook::notifications(), ...Hook::lifecycle()];

        sort($union);
        $all = Hook::all();
        sort($all);

        self::assertSame($all, $union, 'Every hook must be blockable, a notification, or lifecycle, and only one of them.');
    }

    /**
     * The one mistake this naming invites: subscribing to the past tense when
     * you meant the present one. Tooling uses the twin to catch it.
     */
    public function test_a_completed_hook_points_back_at_its_blocking_twin(): void
    {
        self::assertSame(Hook::DOMAIN_CREATING, Hook::blockableTwin(Hook::DOMAIN_CREATED));
        self::assertSame(Hook::DATABASE_CREATING, Hook::blockableTwin(Hook::DATABASE_CREATED));

        // Nothing blocks a rename, so there is no twin to suggest.
        self::assertNull(Hook::blockableTwin(Hook::DOMAIN_RENAMED));
    }

    public function test_every_twin_maps_a_notification_to_a_blockable_hook(): void
    {
        foreach (Hook::notifications() as $hook) {
            $twin = Hook::blockableTwin($hook);

            if ($twin !== null) {
                self::assertTrue(Hook::isBlockable($twin));
                self::assertSame(Hook::resource($hook), Hook::resource($twin));
            }
        }
    }

    public function test_it_suggests_the_nearest_hook_for_a_typo(): void
    {
        self::assertSame(Hook::DOMAIN_CREATED, Hook::closest('domain.create'));
        self::assertSame(Hook::ACCOUNT_SUSPENDED, Hook::closest('account.suspend'));
        self::assertSame(Hook::DNS_RECORD_DELETED, Hook::closest('dns_record.delete'));
    }

    public function test_it_suggests_nothing_for_a_name_that_is_not_close(): void
    {
        self::assertNull(Hook::closest('completely_unrelated_nonsense'));
    }

    public function test_the_resource_is_the_part_before_the_dot(): void
    {
        self::assertSame('domain', Hook::resource(Hook::DOMAIN_CREATING));
        self::assertSame('dns_record', Hook::resource(Hook::DNS_RECORD_UPDATED));
        self::assertSame('email_account', Hook::resource(Hook::EMAIL_ACCOUNT_CREATED));
    }

    public function test_old_style_names_are_not_accepted(): void
    {
        foreach (['before_domain_creation', 'after_domain_creation', 'plugin_settings_updated'] as $retired) {
            self::assertFalse(Hook::isKnown($retired));
        }
    }
}
