<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Exception\ManifestException;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Manifest;
use AdminBolt\Plugin\Tests\TestCase;

final class ManifestTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'acme-widgets',
            'name' => 'Acme Widgets',
            'version' => '1.2.3',
            'runtime' => ['entrypoint' => 'public/index.php', 'transport' => 'http'],
        ], $overrides);
    }

    public function test_a_minimal_manifest_is_valid(): void
    {
        self::assertSame([], Manifest::validate($this->valid()));
    }

    public function test_the_id_must_be_usable_as_a_directory_name(): void
    {
        foreach (['Acme Widgets', 'acme_widgets', 'acme.widgets', '../escape', '9lives'] as $id) {
            self::assertNotSame([], Manifest::validate($this->valid(['id' => $id])), sprintf('"%s" should be rejected.', $id));
        }

        self::assertSame([], Manifest::validate($this->valid(['id' => 'acme-widgets-2'])));
    }

    public function test_a_completed_hook_cannot_be_blocking(): void
    {
        $errors = Manifest::validate($this->valid([
            'hooks' => [['event' => Hook::DOMAIN_CREATED, 'blocking' => true]],
        ]));

        self::assertNotSame([], $errors);
        self::assertStringContainsString('already happened', $errors[0]);
    }

    public function test_a_blocking_timeout_is_capped(): void
    {
        $errors = Manifest::validate($this->valid([
            'hooks' => [['event' => Hook::DOMAIN_CREATING, 'blocking' => true, 'timeout' => 600]],
        ]));

        self::assertNotSame([], $errors);
        self::assertStringContainsString('between 1 and 30 seconds', $errors[0]);
    }

    public function test_the_entrypoint_cannot_escape_the_plugin_directory(): void
    {
        $errors = Manifest::validate($this->valid(['runtime' => ['entrypoint' => '../../../etc/passwd']]));

        self::assertNotSame([], $errors);
    }

    public function test_a_secret_setting_cannot_carry_a_default(): void
    {
        $errors = Manifest::validate($this->valid([
            'settings' => [['key' => 'api_token', 'type' => 'secret', 'default' => 'hunter2']],
        ]));

        self::assertNotSame([], $errors);
        self::assertStringContainsString('must not carry a default', $errors[0]);
    }

    public function test_every_problem_is_reported_at_once(): void
    {
        $errors = Manifest::validate([
            'id' => 'Bad Id',
            'name' => 'x',
            'version' => 'v1',
            'runtime' => ['entrypoint' => 'index.php', 'transport' => 'smoke-signal'],
        ]);

        self::assertGreaterThanOrEqual(3, count($errors));
    }

    public function test_hooks_normalise_to_objects_with_defaults(): void
    {
        $manifest = Manifest::fromArray($this->valid([
            'hooks' => [Hook::DOMAIN_CREATED, ['event' => Hook::DOMAIN_CREATING, 'blocking' => true, 'timeout' => 3]],
        ]));

        self::assertSame(
            [
                ['event' => Hook::DOMAIN_CREATED, 'blocking' => false, 'timeout' => 5],
                ['event' => Hook::DOMAIN_CREATING, 'blocking' => true, 'timeout' => 3],
            ],
            $manifest->hooks()
        );
    }

    public function test_an_invalid_manifest_cannot_be_constructed(): void
    {
        $this->expectException(ManifestException::class);

        Manifest::fromArray(['id' => 'ok', 'name' => 'Ok']);
    }

    public function test_a_ui_page_must_name_a_panel_a_slug_and_a_title(): void
    {
        self::assertSame([], Manifest::validate($this->valid([
            'ui' => [['panel' => 'client', 'slug' => 'zones', 'title' => 'Zones']],
        ])));

        self::assertNotSame([], Manifest::validate($this->valid([
            'ui' => [['panel' => 'everyone', 'slug' => 'zones', 'title' => 'Zones']],
        ])));

        self::assertNotSame([], Manifest::validate($this->valid([
            'ui' => [['panel' => 'client', 'slug' => 'Zones Page', 'title' => 'Zones']],
        ])));

        self::assertNotSame([], Manifest::validate($this->valid([
            'ui' => [['panel' => 'client', 'slug' => 'zones']],
        ])));
    }

    /**
     * The same slug on the admin and client panels is a normal thing to want:
     * an operator view and a customer view of the same feature.
     */
    public function test_a_slug_may_repeat_across_panels_but_not_within_one(): void
    {
        self::assertSame([], Manifest::validate($this->valid([
            'ui' => [
                ['panel' => 'admin', 'slug' => 'zones', 'title' => 'Zones'],
                ['panel' => 'client', 'slug' => 'zones', 'title' => 'My zones'],
            ],
        ])));

        $errors = Manifest::validate($this->valid([
            'ui' => [
                ['panel' => 'client', 'slug' => 'zones', 'title' => 'Zones'],
                ['panel' => 'client', 'slug' => 'zones', 'title' => 'Zones again'],
            ],
        ]));

        self::assertNotSame([], $errors);
        self::assertStringContainsString('twice', $errors[0]);
    }

    public function test_an_iframe_page_is_served_where_the_panel_looks_for_it(): void
    {
        // There is nothing to configure: the front end lives at /ui/{slug} on
        // the plugin's listener and the panel proxies exactly that.
        self::assertSame([], Manifest::validate($this->valid([
            'ui' => [['panel' => 'admin', 'slug' => 'console', 'title' => 'Console', 'render' => 'iframe']],
        ])));
    }

    public function test_a_frame_can_say_how_it_wants_to_be_sized(): void
    {
        self::assertSame([], Manifest::validate($this->valid([
            'ui' => [[
                'panel' => 'client', 'slug' => 'console', 'title' => 'Console',
                'render' => 'iframe', 'height' => 'auto', 'edge_to_edge' => true, 'width' => 'full',
            ]],
        ])));

        $errors = Manifest::validate($this->valid([
            'ui' => [['panel' => 'client', 'slug' => 'console', 'title' => 'Console', 'render' => 'iframe', 'height' => 'tall']],
        ]));

        self::assertStringContainsString('height must be', $errors[0]);
    }

    public function test_a_declarative_page_cannot_size_a_frame_it_does_not_have(): void
    {
        // The panel draws a declarative page with its own components and
        // decides how tall they are. Accepting a size would be accepting a
        // setting that does nothing.
        $errors = Manifest::validate($this->valid([
            'ui' => [['panel' => 'client', 'slug' => 'console', 'title' => 'Console', 'height' => 'auto']],
        ]));

        self::assertStringContainsString('only a page with "render": "iframe" has a frame', $errors[0]);
    }

    public function test_a_manifest_still_naming_a_path_is_told_it_does_nothing(): void
    {
        // The field was accepted and ignored, which is the worst of both: a
        // manifest that validated and a page that did not work.
        $errors = Manifest::validate($this->valid([
            'ui' => [['panel' => 'admin', 'slug' => 'console', 'title' => 'Console', 'render' => 'iframe', 'path' => '/ui/console']],
        ]));

        self::assertNotSame([], $errors);
        self::assertStringContainsString('no longer used', $errors[0]);
        self::assertStringContainsString("app('console'", $errors[0]);
    }

    public function test_scopes_must_name_an_api_a_resource_and_an_access_level(): void
    {
        self::assertSame([], Manifest::validate($this->valid(['api' => ['scopes' => ['admin:hosting-accounts:read', 'client:dns-records:write']]])));
        self::assertNotSame([], Manifest::validate($this->valid(['api' => ['scopes' => ['everything']]])));
    }
}
