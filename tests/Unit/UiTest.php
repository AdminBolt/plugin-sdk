<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Hook\Signature;
use AdminBolt\Plugin\Plugin;
use AdminBolt\Plugin\Tests\TestCase;
use AdminBolt\Plugin\Ui\Action;
use AdminBolt\Plugin\Ui\Alert;
use AdminBolt\Plugin\Ui\Column;
use AdminBolt\Plugin\Ui\Field;
use AdminBolt\Plugin\Ui\Form;
use AdminBolt\Plugin\Ui\Page;
use AdminBolt\Plugin\Ui\Section;
use AdminBolt\Plugin\Ui\Stat;
use AdminBolt\Plugin\Ui\Table;
use AdminBolt\Plugin\Ui\UiRequest;
use AdminBolt\Plugin\Ui\UiResponse;

final class UiTest extends TestCase
{
    private function manifestWithUi(): \AdminBolt\Plugin\Manifest
    {
        return $this->manifest([
            'ui' => [
                ['panel' => 'client', 'slug' => 'zones', 'title' => 'Zones'],
            ],
        ]);
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function uiRequest(string $slug, ?string $action = null, array $extra = []): array
    {
        $body = json_encode([
            'slug' => $slug,
            'panel' => 'client',
            'viewer' => ['id' => 3, 'name' => 'Jo'],
            'hosting_account' => ['id' => 7, 'username' => 'acme'],
            'action' => $action,
            'params' => [],
            'input' => [],
            'arguments' => [],
            'locale' => 'en',
            ...$extra,
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();

        return [
            [
                Signature::HEADER_SIGNATURE => Signature::compute(self::HOOK_SECRET, $timestamp, $body),
                Signature::HEADER_TIMESTAMP => (string) $timestamp,
            ],
            $body,
        ];
    }

    public function test_a_page_is_rendered_as_a_description_not_as_markup(): void
    {
        $plugin = Plugin::create($this->manifestWithUi(), $this->config());
        $plugin->page('zones', fn (UiRequest $r) => Page::make('Zones')
            ->subheading('for ' . $r->hostingAccountUsername())
            ->stats(Stat::make('Total', 3)->color('success'))
            ->add(Table::make()
                ->columns(Column::make('name', 'Name'), Column::make('status', 'Status')->badge(['live' => 'success']))
                ->rows([['name' => 'example.com', 'status' => 'live']])
                ->keyedBy('name')));

        [$headers, $body] = $this->uiRequest('zones');
        $result = $plugin->httpRuntime()->handle('POST', '/ui/zones', $headers, $body);

        self::assertSame(200, $result->status);

        $page = $result->json()['page'];
        self::assertSame('Zones', $page['heading']);
        self::assertSame('for acme', $page['subheading']);
        self::assertSame('3', $page['stats'][0]['value']);
        self::assertSame('example.com', $page['components'][0]['rows'][0]['name']);

        // There is no HTML anywhere in the payload, which is what makes the
        // panel's rendering injection-free by construction.
        self::assertStringNotContainsString('<', $result->body);
    }

    public function test_an_unsigned_ui_request_never_reaches_a_handler(): void
    {
        $reached = false;
        $plugin = Plugin::create($this->manifestWithUi(), $this->config());
        $plugin->page('zones', function () use (&$reached) {
            $reached = true;

            return Page::make('Zones');
        });

        [, $body] = $this->uiRequest('zones');
        $result = $plugin->httpRuntime()->handle('POST', '/ui/zones', [], $body);

        self::assertSame(401, $result->status);
        self::assertFalse($reached, 'A page rendered for a request that was never authenticated.');
    }

    /**
     * The identity a plugin scopes its data by has to come from the panel,
     * signed, and not from anything the browser can set.
     */
    public function test_the_viewer_identity_comes_from_the_signed_envelope(): void
    {
        $plugin = Plugin::create($this->manifestWithUi(), $this->config());
        $plugin->page('zones', fn (UiRequest $r) => Page::make($r->hostingAccountUsername() ?? 'nobody'));

        [$headers, $body] = $this->uiRequest('zones');
        $result = $plugin->httpRuntime()->handle('POST', '/ui/zones', $headers, $body);

        self::assertSame('acme', $result->json()['page']['heading']);
    }

    public function test_an_unregistered_page_is_refused(): void
    {
        $plugin = Plugin::create($this->manifestWithUi(), $this->config());

        [$headers, $body] = $this->uiRequest('secret-admin-page');
        $result = $plugin->httpRuntime()->handle('POST', '/ui/secret-admin-page', $headers, $body);

        self::assertSame(404, $result->status);
    }

    public function test_an_unregistered_action_is_refused_before_any_plugin_code_runs(): void
    {
        $plugin = Plugin::create($this->manifestWithUi(), $this->config());
        $plugin->page('zones', fn () => Page::make('Zones'));
        $plugin->action('sync', fn () => UiResponse::notify('Synced.'));

        [$headers, $body] = $this->uiRequest('zones', 'drop_everything');
        $result = $plugin->httpRuntime()->handle('POST', '/ui/zones/drop_everything', $headers, $body);

        self::assertSame(404, $result->status);
    }

    public function test_an_action_returns_a_notification(): void
    {
        $plugin = Plugin::create($this->manifestWithUi(), $this->config());
        $plugin->action('sync', fn (UiRequest $r) => UiResponse::notify('Synced ' . $r->argument('key') . '.'));

        [$headers, $body] = $this->uiRequest('zones', 'sync', ['arguments' => ['key' => 'example.com']]);
        $result = $plugin->httpRuntime()->handle('POST', '/ui/zones/sync', $headers, $body);

        self::assertSame('Synced example.com.', $result->json()['result']['message']);
        self::assertTrue($result->json()['result']['refresh']);
    }

    public function test_a_form_submission_can_come_back_invalid(): void
    {
        $plugin = Plugin::create($this->manifestWithUi(), $this->config());
        $plugin->action('save', function (UiRequest $r) {
            if (!str_starts_with((string) $r->input('token'), 'cf_')) {
                return UiResponse::invalid(['token' => 'A Cloudflare token starts with cf_.']);
            }

            return UiResponse::notify('Saved.');
        });

        [$headers, $body] = $this->uiRequest('zones', 'save', ['input' => ['token' => 'nope']]);
        $result = $plugin->httpRuntime()->handle('POST', '/ui/zones/save', $headers, $body);

        self::assertSame(['token' => ['A Cloudflare token starts with cf_.']], $result->json()['result']['errors']);
    }

    /**
     * A stored credential must not be handed back to the browser just because
     * the plugin passed it to the field it belongs to.
     */
    public function test_a_secret_field_never_serialises_its_value(): void
    {
        $field = Field::secret('api_token', 'API token')
            ->value('cf_live_supersecret')
            ->placeholder('Set, leave blank to keep');

        $encoded = json_encode($field, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('supersecret', $encoded);
        self::assertArrayNotHasKey('value', $field->jsonSerialize());
    }

    public function test_a_handler_that_throws_does_not_show_the_viewer_a_stack_trace(): void
    {
        $plugin = Plugin::create($this->manifestWithUi(), $this->config());
        $plugin->page('zones', fn () => throw new \RuntimeException('DB password is hunter2'));

        [$headers, $body] = $this->uiRequest('zones');
        $result = $plugin->httpRuntime()->handle('POST', '/ui/zones', $headers, $body);

        self::assertSame(200, $result->status);
        self::assertSame('error', $result->json()['status']);
        self::assertStringNotContainsString('hunter2', $result->body);
    }

    public function test_a_page_collects_every_action_it_can_trigger(): void
    {
        $page = Page::make('Zones')
            ->headerActions(Action::make('sync', 'Sync'))
            ->add(
                Alert::warning('Token expired')->action(Action::make('renew', 'Renew')),
                Section::make('Settings')->add(
                    Form::make('save')->fields(Field::text('name', 'Name')),
                    Table::make()->rowActions(Action::make('purge', 'Purge')),
                ),
            );

        $names = $page->actionNames();
        sort($names);

        self::assertSame(['purge', 'renew', 'save', 'sync'], $names);
    }

    public function test_a_page_slug_must_be_url_safe(): void
    {
        $plugin = Plugin::create($this->manifestWithUi(), $this->config());

        $this->expectException(\AdminBolt\Plugin\Exception\PluginException::class);

        $plugin->page('Not A Slug', fn () => Page::make('x'));
    }

    public function test_polling_is_floored_so_a_page_cannot_hammer_the_plugin(): void
    {
        $page = Page::make('Zones')->poll(1);

        self::assertSame(5, $page->jsonSerialize()['poll']);
    }

    public function test_the_health_probe_reports_pages_and_actions(): void
    {
        $plugin = Plugin::create($this->manifestWithUi(), $this->config());
        $plugin->page('zones', fn () => Page::make('Zones'));
        $plugin->action('sync', fn () => UiResponse::refresh());

        $timestamp = time();
        $result = $plugin->httpRuntime()->handle('GET', '/health', [
            Signature::HEADER_SIGNATURE => Signature::compute(self::HOOK_SECRET, $timestamp, ''),
            Signature::HEADER_TIMESTAMP => (string) $timestamp,
        ], '');

        self::assertSame(['zones'], $result->json()['pages']);
        self::assertSame(['sync'], $result->json()['actions']);
    }
}
