<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * A coloured callout. For telling the viewer something about the state of
 * things, such as a credential that has expired or a sync that is behind.
 */
final class Alert implements Component
{
    private ?string $title = null;

    private ?Action $action = null;

    private function __construct(
        private readonly string $body,
        private readonly string $level,
    ) {
    }

    public static function info(string $body): self
    {
        return new self($body, 'info');
    }

    public static function success(string $body): self
    {
        return new self($body, 'success');
    }

    public static function warning(string $body): self
    {
        return new self($body, 'warning');
    }

    public static function danger(string $body): self
    {
        return new self($body, 'danger');
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * An alert that says something is wrong is more useful with the button
     * that fixes it.
     */
    public function action(Action $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function actionButton(): ?Action
    {
        return $this->action;
    }

    public function type(): string
    {
        return 'alert';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'type' => $this->type(),
            'level' => $this->level,
            'title' => $this->title,
            'body' => $this->body,
            'action' => $this->action,
        ], static fn ($value) => $value !== null);
    }
}
