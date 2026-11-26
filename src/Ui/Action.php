<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * A button. Pressing it calls back into the plugin.
 *
 * The name is what the panel sends back, and the panel will only ever invoke
 * a name the plugin registered with {@see \AdminBolt\Plugin\Plugin::action()}.
 * An action that is not registered cannot be triggered by crafting a request.
 */
final class Action implements \JsonSerializable
{
    public const STYLE_PRIMARY = 'primary';
    public const STYLE_SECONDARY = 'secondary';
    public const STYLE_DANGER = 'danger';

    private ?string $confirm = null;

    private ?string $icon = null;

    private string $style = self::STYLE_SECONDARY;

    private bool $disabled = false;

    /** @var array{column: string, values: list<string>}|null */
    private ?array $onlyWhen = null;

    /** @var array<string, mixed> */
    private array $arguments = [];

    private function __construct(
        private readonly string $name,
        private readonly string $label,
    ) {
    }

    public static function make(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public function primary(): self
    {
        $this->style = self::STYLE_PRIMARY;

        return $this;
    }

    /**
     * Renders in the panel's destructive colour. Pair it with confirm():
     * styling something as dangerous without asking is a trap, not a warning.
     */
    public function danger(): self
    {
        $this->style = self::STYLE_DANGER;

        return $this;
    }

    /**
     * Ask before running. The panel shows this as a modal, and the action
     * only reaches the plugin if the person confirms.
     */
    public function confirm(string $message): self
    {
        $this->confirm = $message;

        return $this;
    }

    public function icon(string $heroicon): self
    {
        $this->icon = $heroicon;

        return $this;
    }

    public function disabled(bool $disabled = true, ?string $reason = null): self
    {
        $this->disabled = $disabled;

        if ($reason !== null) {
            $this->arguments['disabled_reason'] = $reason;
        }

        return $this;
    }

    /**
     * In a table, show this button only on the rows whose column holds one
     * of these values: "Turn on" on the rows that are off, "Turn off" on the
     * rows that are on. Elsewhere it is ignored.
     *
     * It decides what is drawn, not what is allowed. A hidden button's
     * request can still be crafted, so the handler checks the row as it
     * would anyway. A panel older than this field draws the button on every
     * row.
     */
    public function onlyWhen(string $column, string ...$values): self
    {
        $this->onlyWhen = ['column' => $column, 'values' => array_values($values)];

        return $this;
    }

    /**
     * Values handed back to the plugin when the action runs, such as which
     * row a table action belongs to.
     *
     * These make the round trip through the browser, so they are visible and
     * alterable by whoever is looking at the page. Treat them as a hint about
     * intent, never as authorisation: re-check on arrival that the viewer may
     * act on what they name.
     *
     * @param array<string, mixed> $arguments
     */
    public function arguments(array $arguments): self
    {
        $this->arguments = [...$this->arguments, ...$arguments];

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'name' => $this->name,
            'label' => $this->label,
            'style' => $this->style,
            'icon' => $this->icon,
            'confirm' => $this->confirm,
            'disabled' => $this->disabled ?: null,
            'arguments' => $this->arguments ?: null,
            'only_when' => $this->onlyWhen,
        ], static fn ($value) => $value !== null);
    }
}
