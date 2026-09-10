<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * Inputs and a submit button.
 *
 * Submitting sends the values to the action named by submit(), which the
 * plugin must have registered. Validate them on arrival: "required" is a
 * courtesy to the person filling the form in, not a guarantee to the plugin.
 */
final class Form implements Component
{
    /** @var list<Field> */
    private array $fields = [];

    private string $submitLabel = 'Save';

    private ?string $description = null;

    private function __construct(private readonly string $action)
    {
    }

    /**
     * @param string $action the registered action name that receives the
     *        submitted values
     */
    public static function make(string $action): self
    {
        return new self($action);
    }

    public function fields(Field ...$fields): self
    {
        $this->fields = [...$this->fields, ...$fields];

        return $this;
    }

    public function submitLabel(string $label): self
    {
        $this->submitLabel = $label;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function type(): string
    {
        return 'form';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'type' => $this->type(),
            'action' => $this->action,
            'fields' => $this->fields,
            'submit_label' => $this->submitLabel,
            'description' => $this->description,
        ], static fn ($value) => $value !== null);
    }
}
