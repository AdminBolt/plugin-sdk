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

    public function test_an_after_hook_cannot_be_blocking(): void
    {
        $errors = Manifest::validate($this->valid([
            'hooks' => [['event' => Hook::AFTER_DOMAIN_CREATION, 'blocking' => true]],
        ]));

        self::assertNotSame([], $errors);
        self::assertStringContainsString('only before_* hooks', $errors[0]);
    }

    public function test_a_blocking_timeout_is_capped(): void
    {
        $errors = Manifest::validate($this->valid([
            'hooks' => [['event' => Hook::BEFORE_DOMAIN_CREATION, 'blocking' => true, 'timeout' => 600]],
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
            'hooks' => [Hook::AFTER_DOMAIN_CREATION, ['event' => Hook::BEFORE_DOMAIN_CREATION, 'blocking' => true, 'timeout' => 3]],
        ]));

        self::assertSame(
            [
                ['event' => Hook::AFTER_DOMAIN_CREATION, 'blocking' => false, 'timeout' => 5],
                ['event' => Hook::BEFORE_DOMAIN_CREATION, 'blocking' => true, 'timeout' => 3],
            ],
            $manifest->hooks()
        );
    }

    public function test_an_invalid_manifest_cannot_be_constructed(): void
    {
        $this->expectException(ManifestException::class);

        Manifest::fromArray(['id' => 'ok', 'name' => 'Ok']);
    }

    public function test_scopes_must_name_an_api_a_resource_and_an_access_level(): void
    {
        self::assertSame([], Manifest::validate($this->valid(['api' => ['scopes' => ['admin:hosting-accounts:read', 'client:dns-records:write']]])));
        self::assertNotSame([], Manifest::validate($this->valid(['api' => ['scopes' => ['everything']]])));
    }
}
