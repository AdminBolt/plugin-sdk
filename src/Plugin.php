<?php

declare(strict_types=1);

namespace AdminBolt\Plugin;

use AdminBolt\Plugin\Api\AdminApi;
use AdminBolt\Plugin\Api\ApiClient;
use AdminBolt\Plugin\Api\ClientApi;
use AdminBolt\Plugin\Hook\Dispatcher;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookHandler;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;
use AdminBolt\Plugin\Http\HttpClient;
use AdminBolt\Plugin\Logging\FileLogger;
use AdminBolt\Plugin\Logging\Logger;
use AdminBolt\Plugin\Logging\NullLogger;
use AdminBolt\Plugin\Runtime\CliRuntime;
use AdminBolt\Plugin\Runtime\HttpRuntime;
use AdminBolt\Plugin\Storage\Store;
use AdminBolt\Plugin\Ui\Page;
use AdminBolt\Plugin\Ui\UiRequest;
use AdminBolt\Plugin\Ui\UiResponse;
use AdminBolt\Plugin\Ui\UiRouter;

/**
 * A plugin.
 *
 * This is the whole surface most plugins need:
 *
 *     $plugin = Plugin::boot(__DIR__);
 *
 *     $plugin->on(Hook::DOMAIN_CREATING, function (HookRequest $hook) use ($plugin) {
 *         $domain = (string) $hook->payload('domain');
 *
 *         if (str_ends_with($domain, '.test')) {
 *             return HookResponse::reject('.test domains cannot be hosted here.');
 *         }
 *
 *         return HookResponse::ok();
 *     });
 *
 *     $plugin->run();
 *
 * Nothing here touches the panel's codebase. A plugin is an ordinary PHP
 * application that happens to speak two contracts: the signed hook envelope
 * and the panel's REST API. It can be written with any framework or none, it
 * ships and versions on its own, and the panel neither loads its classes nor
 * shares a process with it.
 */
final class Plugin
{
    private ?AdminApi $admin = null;

    private ?ClientApi $client = null;

    private readonly UiRouter $ui;

    public function __construct(
        private readonly Manifest $manifest,
        private readonly Config $config,
        private readonly Dispatcher $dispatcher,
        private readonly Logger $logger,
        private readonly ?HttpClient $http = null,
        ?UiRouter $ui = null,
    ) {
        $this->ui = $ui ?? new UiRouter($logger);
    }

    /**
     * The normal entry point: find plugin.json, load the configuration the
     * panel wrote, and start logging where the panel expects to find it.
     *
     * @param string|null $directory where to start looking for plugin.json.
     *        Defaults to the directory of the file that called this, so an
     *        entrypoint in public/ finds a manifest in the plugin root.
     */
    public static function boot(?string $directory = null, ?Logger $logger = null, ?HttpClient $http = null): self
    {
        $directory ??= self::callerDirectory();

        $manifest = Manifest::discover($directory);
        $config = Config::load();
        $logger ??= self::defaultLogger($config);

        return new self($manifest, $config, new Dispatcher($logger), $logger, $http, new UiRouter($logger));
    }

    /**
     * Explicit construction, for tests and for a plugin that gets its
     * configuration from somewhere other than the panel's runtime file.
     */
    public static function create(Manifest $manifest, Config $config, ?Logger $logger = null, ?HttpClient $http = null): self
    {
        $logger ??= new NullLogger();

        return new self($manifest, $config, new Dispatcher($logger), $logger, $http, new UiRouter($logger));
    }

    /**
     * Subscribe to a hook.
     *
     * The handler receives a {@see HookRequest} and returns a
     * {@see HookResponse}. Returning nothing is the same as returning ok(),
     * which is what a notification handler usually wants.
     *
     * @param callable(HookRequest): (HookResponse|null) $handler
     */
    public function on(string $hook, callable $handler): self
    {
        $this->dispatcher->on($hook, static function (HookRequest $request) use ($handler): HookResponse {
            $result = $handler($request);

            return $result instanceof HookResponse ? $result : HookResponse::ok();
        });

        return $this;
    }

    public function register(HookHandler $handler): self
    {
        $this->dispatcher->register($handler);

        return $this;
    }

    /**
     * Add a page to the panel.
     *
     * The slug must also appear under "ui" in plugin.json, which is what puts
     * it in the navigation and decides which panel it belongs to. This
     * handler supplies what is on it.
     *
     *     $plugin->page('overview', fn (UiRequest $request) => Page::make('Cloudflare')
     *         ->stats(Stat::make('Zones', $this->zoneCount($request)))
     *         ->add(Table::make()->columns(...)->rows(...)));
     *
     * The handler describes the page; the panel renders it with its own
     * components. Nothing here emits HTML.
     *
     * @param callable(UiRequest): Page $handler
     */
    public function page(string $slug, callable $handler): self
    {
        $this->ui->page($slug, $handler);

        return $this;
    }

    /**
     * Handle a button press or a form submission from one of the pages.
     *
     * Only registered names are reachable, and the panel will not invoke one
     * that is not on the page it rendered.
     *
     * @param callable(UiRequest): UiResponse $handler
     */
    public function action(string $name, callable $handler): self
    {
        $this->ui->action($name, $handler);

        return $this;
    }

    /**
     * Serve a built front end for a page, instead of describing one.
     *
     * For the rare page the panel's components cannot express: output that
     * streams while something runs, a canvas, an editor. The manifest entry
     * for the page has to declare "render": "iframe", and the two have to
     * agree or the panel asks for a description this plugin will not send.
     *
     *     $plugin->app('console', __DIR__ . '/../ui/dist');
     *
     * What is given up is everything the declarative path does for free: the
     * operator's theme, dark mode, the phone layout, translation, and having
     * no markup to escape.
     */
    public function app(string $slug, string $directory): self
    {
        $this->ui->app($slug, $directory);

        return $this;
    }

    public function ui(): UiRouter
    {
        return $this->ui;
    }

    /**
     * Runs after every delivery, for logging and metrics. Cannot change the
     * outcome.
     *
     * @param callable(HookRequest, HookResponse): void $observer
     */
    public function observe(callable $observer): self
    {
        $this->dispatcher->observe($observer);

        return $this;
    }

    /**
     * Serve deliveries, using whichever transport the manifest declares.
     * The last line of a plugin's entrypoint.
     */
    public function run(): void
    {
        $this->warnAboutGaps();

        if ($this->manifest->transport() === 'cli') {
            (new CliRuntime($this->config, $this->dispatcher, $this->logger))->run();
        }

        $this->httpRuntime()->run();
    }

    public function httpRuntime(): HttpRuntime
    {
        return new HttpRuntime($this->config, $this->manifest, $this->dispatcher, $this->logger, $this->ui);
    }

    /**
     * The panel's admin API, server-wide.
     */
    public function admin(): AdminApi
    {
        return $this->admin ??= new AdminApi(
            ApiClient::fromConfig($this->config, '', $this->http, $this->logger)
        );
    }

    /**
     * The panel's client API.
     *
     * With a username, acts on that account, which requires an admin or
     * reseller key. Without one, acts on whatever account the key belongs to.
     */
    public function client(?string $hostingAccount = null): ClientApi
    {
        $api = $this->client ??= new ClientApi(
            ApiClient::fromConfig($this->config, '/client', $this->http, $this->logger)
        );

        return $hostingAccount === null ? $api : $api->forAccount($hostingAccount);
    }

    /**
     * The client API scoped to the account a request concerns.
     *
     * Takes a hook delivery or a page request, because both carry the account
     * in the panel's signed envelope and both want the same thing: act on
     * that account and no other. This is the safe default, and the reason a
     * plugin should almost never name an account itself.
     */
    public function clientFor(HookRequest|UiRequest $request): ClientApi
    {
        $username = $request->hostingAccountUsername();

        return $username === null ? $this->client() : $this->client($username);
    }

    /**
     * Where this plugin keeps what it knows about one account.
     *
     * Settings are the operator's and apply to the whole plugin; this is the
     * other kind of state, the kind a page accumulates for the customer
     * looking at it. Pass the request and it is scoped to that account; pass
     * nothing and it is the plugin's own.
     */
    public function store(HookRequest|UiRequest|null $request = null): Store
    {
        $account = match (true) {
            $request instanceof UiRequest => $request->hostingAccountUsername(),
            $request instanceof HookRequest => $request->hostingAccountUsername(),
            default => null,
        };

        return Store::forAccount($this->config->path('data'), $account);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function manifest(): Manifest
    {
        return $this->manifest;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function dispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    /**
     * A setting the operator filled in, as declared in plugin.json.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config->setting($key, $default);
    }

    /**
     * Mismatches between what the manifest promises and what the plugin
     * actually registered.
     *
     * Every one of these is silent at runtime: the panel keeps delivering a
     * hook nothing handles, or puts a page in the menu that opens onto an
     * error. Logged at startup rather than thrown, since a half-finished
     * plugin should still boot.
     */
    private function warnAboutGaps(): void
    {
        $unhandled = array_diff($this->manifest->hookNames(), $this->dispatcher->registeredHooks());

        if ($unhandled !== []) {
            $this->logger->warning('Manifest subscribes to hooks with no handler registered', [
                'hooks' => array_values($unhandled),
            ]);
        }

        $undeclared = array_diff($this->dispatcher->registeredHooks(), $this->manifest->hookNames());

        if ($undeclared !== []) {
            // The panel only delivers what the manifest declared, so these
            // handlers will never run.
            $this->logger->warning('Handlers registered for hooks the manifest does not declare; the panel will not deliver them', [
                'hooks' => array_values($undeclared),
            ]);
        }

        // A page in the navigation with nothing behind it is a menu entry
        // that opens onto an error, which is worse than no menu entry.
        $declaredPages = array_filter(
            $this->manifest->ui(),
            static fn (array $page): bool => $page['render'] !== 'iframe'
        );
        $missingPages = array_diff(
            array_map(static fn (array $page): string => $page['slug'], $declaredPages),
            $this->ui->pageSlugs()
        );

        if ($missingPages !== []) {
            $this->logger->warning('Manifest declares panel pages with no handler registered', [
                'pages' => array_values($missingPages),
            ]);
        }

        $undeclaredPages = array_diff($this->ui->pageSlugs(), $this->manifest->pageSlugs());

        if ($undeclaredPages !== []) {
            // Nothing links to them, so nobody will ever open them.
            $this->logger->warning('Pages registered that the manifest does not declare; they will not appear in the panel', [
                'pages' => array_values($undeclaredPages),
            ]);
        }
    }

    private static function defaultLogger(Config $config): Logger
    {
        $directory = $config->paths['logs'] ?? null;

        if ($directory === null) {
            return new NullLogger();
        }

        return new FileLogger(
            rtrim($directory, '/') . '/' . $config->pluginId . '.log',
            (string) $config->setting('log_level', 'info'),
        );
    }

    private static function callerDirectory(): string
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? null;
        $file = $frame['file'] ?? null;

        return is_string($file) ? dirname($file) : (getcwd() ?: '.');
    }
}
