<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * One number with a label, rendered as a card in the panel's stat row.
 */
final class Stat implements Component
{
    private ?string $description = null;

    private ?string $icon = null;

    private ?string $color = null;

    /** @var list<int|float>|null */
    private ?array $chart = null;

    private function __construct(
        private readonly string $label,
        private readonly string $value,
    ) {
    }

    public static function make(string $label, string|int|float $value): self
    {
        return new self($label, (string) $value);
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function icon(string $heroicon): self
    {
        $this->icon = $heroicon;

        return $this;
    }

    /**
     * One of the panel's semantic colours: primary, success, warning, danger,
     * info or gray. Not a hex value, so a plugin cannot fight the operator's
     * theme or produce something unreadable in dark mode.
     */
    public function color(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    /** @param list<int|float> $values */
    public function chart(array $values): self
    {
        $this->chart = $values;

        return $this;
    }

    public function type(): string
    {
        return 'stat';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'type' => $this->type(),
            'label' => $this->label,
            'value' => $this->value,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'chart' => $this->chart,
        ], static fn ($value) => $value !== null);
    }
}
