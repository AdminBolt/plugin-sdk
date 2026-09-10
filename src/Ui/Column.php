<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * One column of a {@see Table}.
 */
final class Column implements \JsonSerializable
{
    public const TYPE_TEXT = 'text';
    public const TYPE_BADGE = 'badge';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_BYTES = 'bytes';

    private string $type = self::TYPE_TEXT;

    /** @var array<string, string> */
    private array $colors = [];

    private bool $wrap = false;

    private ?string $description = null;

    private function __construct(
        private readonly string $key,
        private readonly string $label,
    ) {
    }

    public static function make(string $key, string $label): self
    {
        return new self($key, $label);
    }

    /**
     * Renders each value as a pill.
     *
     * @param array<string, string> $colors value to one of the panel's
     *        semantic colours, so "active" can be green and "failed" red
     */
    public function badge(array $colors = []): self
    {
        $this->type = self::TYPE_BADGE;
        $this->colors = $colors;

        return $this;
    }

    public function boolean(): self
    {
        $this->type = self::TYPE_BOOLEAN;

        return $this;
    }

    public function number(): self
    {
        $this->type = self::TYPE_NUMBER;

        return $this;
    }

    /**
     * The panel formats the value in the viewer's timezone and locale, so
     * send an ISO 8601 string and let it decide how to show it.
     */
    public function dateTime(): self
    {
        $this->type = self::TYPE_DATETIME;

        return $this;
    }

    /** Formats a byte count as KB, MB or GB. */
    public function bytes(): self
    {
        $this->type = self::TYPE_BYTES;

        return $this;
    }

    public function wrap(bool $wrap = true): self
    {
        $this->wrap = $wrap;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'colors' => $this->colors ?: null,
            'wrap' => $this->wrap ?: null,
            'description' => $this->description,
        ], static fn ($value) => $value !== null);
    }
}
