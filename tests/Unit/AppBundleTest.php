<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Config;
use AdminBolt\Plugin\Manifest;
use AdminBolt\Plugin\Plugin;
use AdminBolt\Plugin\Tests\TestCase;
use AdminBolt\Plugin\Ui\AppBundle;

/**
 * Serving a built front end.
 *
 * Two things have to hold: a request cannot leave the bundle directory, and a
 * front end with its own routes still works when the browser asks for a path
 * only it knows about.
 */
final class AppBundleTest extends TestCase
{
    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/bolt-bundle-' . bin2hex(random_bytes(6));
        $this->outside = sys_get_temp_dir() . '/bolt-outside-' . bin2hex(random_bytes(6));

        mkdir($this->root . '/assets', 0o755, true);
        mkdir($this->outside, 0o755, true);

        file_put_contents($this->root . '/index.html', '<!doctype html><div id="root"></div>');
        file_put_contents($this->root . '/assets/app.js', 'console.log(1)');
        file_put_contents($this->root . '/assets/app.css', 'body{}');
        file_put_contents($this->outside . '/secret.txt', 'not yours');
    }

    protected function tearDown(): void
    {
        foreach ([$this->root . '/assets', $this->root, $this->outside] as $directory) {
            foreach ((array) glob($directory . '/*') as $file) {
                if (is_file((string) $file)) {
                    @unlink((string) $file);
                }
            }

            @rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_it_serves_a_file_with_the_type_the_browser_needs(): void
    {
        $bundle = new AppBundle($this->root);

        $js = $bundle->serve('assets/app.js');
        self::assertSame(200, $js->status);
        self::assertSame('text/javascript; charset=utf-8', $js->headers['Content-Type']);
        self::assertSame('console.log(1)', $js->body);

        self::assertSame('text/css; charset=utf-8', $bundle->serve('assets/app.css')->headers['Content-Type']);
    }

    public function test_the_document_is_never_cached_and_the_hashed_files_always_are(): void
    {
        // index.html carries the references to the hashed files. Cached, a
        // deploy leaves browsers on the previous build indefinitely.
        $bundle = new AppBundle($this->root);

        self::assertSame('no-store', $bundle->serve('')->headers['Cache-Control']);
        self::assertStringContainsString('immutable', $bundle->serve('assets/app.js')->headers['Cache-Control']);
    }

    public function test_a_path_the_front_end_owns_falls_back_to_the_document(): void
    {
        // A front end with its own routes gets asked for /applications/3 by
        // the browser, and only it knows what that means.
        $result = (new AppBundle($this->root))->serve('applications/3');

        self::assertSame(200, $result->status);
        self::assertStringContainsString('<div id="root">', $result->body);
    }

    public function test_it_cannot_be_walked_out_of(): void
    {
        $bundle = new AppBundle($this->root);

        foreach ([
            '../' . basename($this->outside) . '/secret.txt',
            '..%2Fsecret.txt',
            'assets/../../' . basename($this->outside) . '/secret.txt',
            '/etc/passwd',
        ] as $attempt) {
            $result = $bundle->serve($attempt);

            self::assertStringNotContainsString('not yours', $result->body, $attempt . ' escaped the bundle');
            self::assertStringNotContainsString('root:', $result->body, $attempt . ' escaped the bundle');
        }
    }

    public function test_a_symlink_out_of_the_bundle_is_refused(): void
    {
        // Resolved physically, so a link planted inside the directory is not
        // a way out of it either.
        symlink($this->outside . '/secret.txt', $this->root . '/assets/leak.txt');

        $result = (new AppBundle($this->root))->serve('assets/leak.txt');

        self::assertStringNotContainsString('not yours', $result->body);
    }

    public function test_it_refuses_a_kind_of_file_a_front_end_is_not_made_of(): void
    {
        file_put_contents($this->root . '/assets/notes.env', 'SECRET=1');

        $result = (new AppBundle($this->root))->serve('assets/notes.env');

        self::assertSame(415, $result->status);
        self::assertStringNotContainsString('SECRET', $result->body);
    }

    public function test_a_bundle_that_was_never_built_says_so(): void
    {
        $result = (new AppBundle($this->root . '/nothing-here'))->serve('');

        self::assertSame(500, $result->status);
        self::assertStringContainsString('has not been built', $result->body);
    }

    public function test_the_runtime_serves_a_registered_bundle_over_get(): void
    {
        $plugin = Plugin::create(
            Manifest::fromArray([
                'id' => 'toolkit',
                'name' => 'Toolkit',
                'version' => '1.0.0',
                'runtime' => ['entrypoint' => 'public/index.php'],
                'ui' => [['panel' => 'client', 'slug' => 'console', 'title' => 'Console', 'render' => 'iframe']],
            ]),
            Config::fromArray([
                'panel' => ['url' => 'https://panel.test'],
                'plugin' => ['id' => 'toolkit'],
                'api' => ['key' => 'k', 'secret' => 's'],
                'hooks' => ['secret' => 'h'],
            ], 'test'),
        );

        $plugin->app('console', $this->root);

        $result = $plugin->httpRuntime()->handle('GET', '/ui/console/assets/app.js', [], '');

        self::assertSame(200, $result->status);
        self::assertSame('console.log(1)', $result->body);

        // An asset GET is the one unsigned path, because a built bundle is
        // the same for everybody and carries nobody's data.
        $document = $plugin->httpRuntime()->handle('GET', '/ui/console', [], '');
        self::assertStringContainsString('<div id="root">', $document->body);
    }

    public function test_an_action_can_answer_a_front_end_with_data(): void
    {
        // A declarative page has no way to receive this: it exists for a page
        // the plugin draws itself, which needs somewhere to read its state.
        $response = \AdminBolt\Plugin\Ui\UiResponse::data(['applications' => ['shop']], 'Found one.')
            ->jsonSerialize();

        self::assertSame(['applications' => ['shop']], $response['data']);
        self::assertSame('Found one.', $response['message']);
        self::assertSame('success', $response['level']);

        // And it does not tell the panel to redraw anything, because for this
        // kind of page there is nothing the panel draws.
        self::assertArrayNotHasKey('refresh', $response);
    }

    public function test_the_runtime_refuses_a_bundle_the_plugin_never_registered(): void
    {
        $plugin = Plugin::create(
            Manifest::fromArray([
                'id' => 'toolkit',
                'name' => 'Toolkit',
                'version' => '1.0.0',
                'runtime' => ['entrypoint' => 'public/index.php'],
            ]),
            Config::fromArray([
                'panel' => ['url' => 'https://panel.test'],
                'plugin' => ['id' => 'toolkit'],
                'api' => ['key' => 'k', 'secret' => 's'],
                'hooks' => ['secret' => 'h'],
            ], 'test'),
        );

        $result = $plugin->httpRuntime()->handle('GET', '/ui/console/assets/app.js', [], '');

        self::assertSame(404, $result->status);
    }
}
