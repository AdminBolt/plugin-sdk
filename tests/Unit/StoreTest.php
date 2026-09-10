<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Storage\Store;
use AdminBolt\Plugin\Tests\TestCase;
use AdminBolt\Plugin\Ui\Output;

/**
 * Per-account state, and the component that shows what a command printed.
 */
final class StoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/bolt-store-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_what_one_account_stores_is_not_what_another_reads(): void
    {
        Store::forAccount($this->directory, 'acme')->put('apps', ['shop']);

        self::assertSame(['shop'], Store::forAccount($this->directory, 'acme')->get('apps'));
        self::assertSame([], Store::forAccount($this->directory, 'other')->get('apps', []));
    }

    public function test_an_account_name_cannot_choose_the_file_it_writes(): void
    {
        // The name comes from the panel's signed envelope, but a store that
        // pastes it into a path would be one traversal away from another
        // plugin's data whatever the source.
        Store::forAccount($this->directory, '../../etc/passwd')->put('x', 1);

        $files = array_map('basename', (array) glob($this->directory . '/*'));

        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/^account-[0-9a-f]{64}\.json$/', (string) $files[0]);
    }

    public function test_it_survives_being_written_and_read_again(): void
    {
        $store = Store::forAccount($this->directory, 'acme');
        $store->put('current', 'shop');
        $store->merge(['deploy' => ['branch' => 'main']]);

        $reopened = Store::forAccount($this->directory, 'acme');

        self::assertSame('shop', $reopened->get('current'));
        self::assertSame(['branch' => 'main'], $reopened->get('deploy'));
        self::assertTrue($reopened->has('current'));

        $reopened->forget('current');

        self::assertFalse(Store::forAccount($this->directory, 'acme')->has('current'));
    }

    public function test_an_unreadable_store_is_empty_rather_than_fatal(): void
    {
        // Losing a page's remembered state is a nuisance. A page that will
        // not render because of it is an outage.
        @mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/account-' . hash('sha256', 'acme') . '.json', 'not json at all');

        self::assertSame([], Store::forAccount($this->directory, 'acme')->all());
    }

    public function test_the_output_component_describes_itself_without_markup(): void
    {
        $output = Output::make("Migrating: users\nDone.")
            ->title('Migration')
            ->status('success')
            ->follow()
            ->lines(24)
            ->jsonSerialize();

        self::assertSame('output', $output['type']);
        self::assertSame('Migration', $output['title']);
        self::assertSame('success', $output['status']);
        self::assertTrue($output['follow']);
        self::assertSame(24, $output['lines']);
        self::assertStringContainsString('Migrating: users', $output['content']);
    }
}
