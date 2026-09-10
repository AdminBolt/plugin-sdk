<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * A page in the panel, described rather than drawn.
 *
 *     return Page::make('Cloudflare')
 *         ->subheading('Zones mirrored from this account')
 *         ->stats(
 *             Stat::make('Zones', 12)->icon('heroicon-o-globe-alt'),
 *             Stat::make('Pending', 1)->color('warning'),
 *         )
 *         ->add(Table::make()->columns(...)->rows(...))
 *         ->headerActions(Action::make('sync', 'Sync now')->primary());
 */
final class Page implements \JsonSerializable
{
    /** @var list<Component> */
    private array $components = [];

    /** @var list<Stat> */
    private array $stats = [];

    /** @var list<Action> */
    private array $headerActions = [];

    private ?string $subheading = null;

    private ?int $pollSeconds = null;

    private function __construct(private readonly string $heading)
    {
    }

    public static function make(string $heading): self
    {
        return new self($heading);
    }

    public function subheading(string $subheading): self
    {
        $this->subheading = $subheading;

        return $this;
    }

    /**
     * The row of cards across the top.
     */
    public function stats(Stat ...$stats): self
    {
        $this->stats = [...$this->stats, ...$stats];

        return $this;
    }

    public function add(Component ...$components): self
    {
        $this->components = [...$this->components, ...$components];

        return $this;
    }

    /**
     * Buttons beside the page title.
     */
    public function headerActions(Action ...$actions): self
    {
        $this->headerActions = [...$this->headerActions, ...$actions];

        return $this;
    }

    /**
     * Ask the panel to re-render this page every few seconds, for a page
     * showing something in progress.
     *
     * Every poll is a request to the plugin, so the panel enforces a floor of
     * five seconds. Use it for a running job, not as a substitute for a page
     * that reports its own state.
     */
    public function poll(int $seconds): self
    {
        $this->pollSeconds = max(5, $seconds);

        return $this;
    }

    /**
     * Every action name this page can trigger, including the ones nested in
     * tables, forms and alerts.
     *
     * The runtime uses it to catch a page wired to an action that was never
     * registered, which otherwise shows up as a dead button.
     *
     * @return list<string>
     */
    public function actionNames(): array
    {
        $names = array_map(static fn (Action $action): string => $action->name(), $this->headerActions);

        foreach ($this->allComponents() as $component) {
            if ($component instanceof Table) {
                foreach ($component->actions() as $action) {
                    $names[] = $action->name();
                }
            }

            if ($component instanceof Form) {
                $names[] = $component->action();
            }

            if ($component instanceof Alert && $component->actionButton() !== null) {
                $names[] = $component->actionButton()->name();
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Components at every depth, flattened.
     *
     * @return list<Component>
     */
    private function allComponents(): array
    {
        $flat = [];

        foreach ($this->components as $component) {
            $flat[] = $component;

            if ($component instanceof Section) {
                foreach ($component->components() as $nested) {
                    $flat[] = $nested;
                }
            }
        }

        return $flat;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'heading' => $this->heading,
            'subheading' => $this->subheading,
            'stats' => $this->stats ?: null,
            'header_actions' => $this->headerActions ?: null,
            'components' => $this->components,
            'poll' => $this->pollSeconds,
        ], static fn ($value) => $value !== null);
    }
}
