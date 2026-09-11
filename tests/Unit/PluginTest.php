<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Http\CurlHttpClient;
use AdminBolt\Plugin\Http\HttpResponse;
use AdminBolt\Plugin\Manifest;
use AdminBolt\Plugin\Plugin;
use AdminBolt\Plugin\Testing\FakeHttpClient;
use AdminBolt\Plugin\Tests\TestCase;

/**
 * The plugin object itself, rather than the delivery path through it.
 */
final class PluginTest extends TestCase
{
    public function test_the_injected_transport_is_what_a_plugin_calls_out_with(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['database' => 'ok']);

        $plugin = Plugin::create($this->manifest(), $this->config(), http: $http);

        $response = $plugin->http()->send('GET', 'https://grafana.test/api/health');

        self::assertSame(200, $response->status);
        self::assertSame('https://grafana.test/api/health', $http->lastRequest()['url']);
    }

    /**
     * The arguments exist for a service behind a certificate nothing signed,
     * which is a production concern. A test that injected a fake still wants
     * the fake.
     */
    public function test_transport_options_do_not_defeat_an_injected_client(): void
    {
        $http = (new FakeHttpClient())->queue(new HttpResponse(204, ''));

        $plugin = Plugin::create($this->manifest(), $this->config(), http: $http);

        self::assertSame($http, $plugin->http(timeout: 5, verifyTls: false));
    }

    public function test_a_plugin_with_no_injected_transport_gets_a_curl_one(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());

        self::assertInstanceOf(CurlHttpClient::class, $plugin->http());
    }

    /**
     * Built once: a page that calls out three times should not open three
     * clients to do it.
     */
    public function test_the_default_transport_is_reused(): void
    {
        $plugin = Plugin::create($this->manifest(), $this->config());

        self::assertSame($plugin->http(), $plugin->http());
    }

    /**
     * A manifest found by walking up from public/index.php would otherwise
     * hand back a path with "/public/.." in the middle of it, which is fine
     * to open and wrong to show somebody.
     */
    public function test_the_plugin_root_is_resolved_rather_than_relative(): void
    {
        $root = sys_get_temp_dir() . '/bolt-sdk-manifest-' . bin2hex(random_bytes(6));
        mkdir($root . '/public', 0o755, true);

        file_put_contents($root . '/plugin.json', json_encode([
            'id' => 'test-plugin',
            'name' => 'Test Plugin',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));

        try {
            $directory = Manifest::discover($root . '/public')->directory();

            self::assertSame(realpath($root), $directory);
            self::assertStringNotContainsString('..', (string) $directory);
        } finally {
            @unlink($root . '/plugin.json');
            @rmdir($root . '/public');
            @rmdir($root);
        }
    }

    public function test_a_manifest_read_from_a_file_knows_the_plugin_root(): void
    {
        $root = sys_get_temp_dir() . '/bolt-sdk-manifest-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);

        file_put_contents($root . '/plugin.json', json_encode([
            'id' => 'test-plugin',
            'name' => 'Test Plugin',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));

        try {
            // realpath, because that is what the plugin gets back: the point
            // of resolving it is that an operator can copy the path.
            self::assertSame(realpath($root), Manifest::fromFile($root . '/plugin.json')->directory());
        } finally {
            @unlink($root . '/plugin.json');
            @rmdir($root);
        }
    }

    public function test_a_manifest_built_from_an_array_has_no_directory(): void
    {
        self::assertNull($this->manifest()->directory());
    }
}
