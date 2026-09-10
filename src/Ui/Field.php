<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * One input in a {@see Form}.
 */
final class Field implements \JsonSerializable
{
    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_NUMBER = 'number';
    public const TYPE_SELECT = 'select';
    public const TYPE_TOGGLE = 'toggle';
    public const TYPE_SECRET = 'secret';
    public const TYPE_DATE = 'date';
    public const TYPE_EMAIL = 'email';
    public const TYPE_URL = 'url';

    private mixed $value = null;

    private bool $required = false;

    private ?string $help = null;

    private ?string $placeholder = null;

    /** @var array<string, string> */
    private array $options = [];

    private bool $disabled = false;

    private function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $type,
    ) {
    }

    public static function text(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_TEXT);
    }

    public static function textarea(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_TEXTAREA);
    }

    public static function number(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_NUMBER);
    }

    /** @param array<string, string> $options value to label */
    public static function select(string $name, string $label, array $options): self
    {
        $field = new self($name, $label, self::TYPE_SELECT);
        $field->options = $options;

        return $field;
    }

    public static function toggle(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_TOGGLE);
    }

    /**
     * A write-only input. The panel renders it masked and, when the plugin
     * sends a value back, sends the placeholder rather than the secret, so a
     * stored credential is never re-served to the browser.
     */
    public static function secret(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_SECRET);
    }

    public static function date(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_DATE);
    }

    public static function email(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_EMAIL);
    }

    public static function url(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_URL);
    }

    public function value(mixed $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function disabled(bool $disabled = true): self
    {
        $this->disabled = $disabled;

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
            'type' => $this->type,
            // A secret's value is never sent to the browser, whatever the
            // plugin passed. Showing the operator that one is set is the job
            // of the placeholder.
            'value' => $this->type === self::TYPE_SECRET ? null : $this->value,
            'required' => $this->required ?: null,
            'help' => $this->help,
            'placeholder' => $this->placeholder,
            'options' => $this->options ?: null,
            'disabled' => $this->disabled ?: null,
        ], static fn ($value) => $value !== null);
    }
}
