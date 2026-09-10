<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

use AdminBolt\Plugin\Exception\PluginException;
use AdminBolt\Plugin\Logging\Logger;
use AdminBolt\Plugin\Logging\NullLogger;

/**
 * Holds a plugin's pages and actions, and runs the right one.
 *
 * The panel can only reach a page or an action the plugin registered here.
 * A request naming anything else is refused before any plugin code runs, so
 * an action is not callable just because someone guessed its name.
 */
final class UiRouter
{
    /** @var array<string, callable(UiRequest): Page> */
    private array $pages = [];

    /** @var array<string, callable(UiRequest): UiResponse> */
    private array $actions = [];

    /** @var array<string, AppBundle> */
    private array $apps = [];

    public function __construct(private readonly Logger $logger = new NullLogger())
    {
    }

    /**
     * @param callable(UiRequest): Page $handler
     */
    public function page(string $slug, callable $handler): self
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $slug) !== 1) {
            throw new PluginException(sprintf(
                'Page slug "%s" must be lower-case kebab-case; it becomes part of the panel URL.',
                $slug
            ));
        }

        $this->pages[$slug] = $handler;

        return $this;
    }

    /**
     * Serve a built front end for a page instead of a description of one.
     *
     * The page's manifest entry has to declare "render": "iframe" as well:
     * that is what tells the panel to embed this rather than ask for a page
     * description, and the two have to agree or the page renders nothing.
     */
    public function app(string $slug, string $directory): self
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $slug) !== 1) {
            throw new PluginException(sprintf(
                'Page slug "%s" must be lower-case kebab-case; it becomes part of the panel URL.',
                $slug
            ));
        }

        $this->apps[$slug] = new AppBundle($directory);

        return $this;
    }

    public function hasApp(string $slug): bool
    {
        return isset($this->apps[$slug]);
    }

    public function bundle(string $slug): ?AppBundle
    {
        return $this->apps[$slug] ?? null;
    }

    /**
     * @param callable(UiRequest): UiResponse $handler
     */
    public function action(string $name, callable $handler): self
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
            throw new PluginException(sprintf('Action name "%s" must be lower-case.', $name));
        }

        $this->actions[$name] = $handler;

        return $this;
    }

    public function hasPage(string $slug): bool
    {
        return isset($this->pages[$slug]);
    }

    public function hasAction(string $name): bool
    {
        return isset($this->actions[$name]);
    }

    /** @return list<string> */
    public function pageSlugs(): array
    {
        return array_keys($this->pages);
    }

    /** @return list<string> */
    public function actionNames(): array
    {
        return array_keys($this->actions);
    }

    public function renderPage(UiRequest $request): Page
    {
        $handler = $this->pages[$request->slug] ?? null;

        if ($handler === null) {
            throw new PluginException(sprintf('No page registered for "%s".', $request->slug));
        }

        $page = $handler($request);

        if (!$page instanceof Page) {
            throw new PluginException(sprintf(
                'The handler for page "%s" returned %s; it must return a Page.',
                $request->slug,
                get_debug_type($page)
            ));
        }

        // A button wired to an action nobody registered does nothing when
        // pressed, and nothing says why. Cheaper to notice here.
        $missing = array_diff($page->actionNames(), $this->actionNames());

        if ($missing !== []) {
            $this->logger->warning('Page references actions that are not registered', [
                'slug' => $request->slug,
                'actions' => array_values($missing),
            ]);
        }

        return $page;
    }

    public function runAction(UiRequest $request): UiResponse
    {
        $name = $request->action;

        if ($name === null || !isset($this->actions[$name])) {
            throw new PluginException(sprintf('No action registered for "%s".', (string) $name));
        }

        $response = $this->actions[$name]($request);

        if (!$response instanceof UiResponse) {
            throw new PluginException(sprintf(
                'The handler for action "%s" returned %s; it must return a UiResponse.',
                $name,
                get_debug_type($response)
            ));
        }

        return $response;
    }
}
