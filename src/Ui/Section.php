<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * A titled group of components, rendered as one card.
 */
final class Section implements Component
{
    /** @var list<Component> */
    private array $components = [];

    private ?string $description = null;

    private ?string $icon = null;

    private bool $collapsed = false;

    private function __construct(private readonly ?string $heading)
    {
    }

    public static function make(?string $heading = null): self
    {
        return new self($heading);
    }

    public function add(Component ...$components): self
    {
        $this->components = [...$this->components, ...$components];

        return $this;
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

    public function collapsed(bool $collapsed = true): self
    {
        $this->collapsed = $collapsed;

        return $this;
    }

    /** @return list<Component> */
    public function components(): array
    {
        return $this->components;
    }

    public function type(): string
    {
        return 'section';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'type' => $this->type(),
            'heading' => $this->heading,
            'description' => $this->description,
            'icon' => $this->icon,
            'collapsed' => $this->collapsed ?: null,
            'components' => $this->components,
        ], static fn ($value) => $value !== null);
    }
}
