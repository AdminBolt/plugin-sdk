<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * What a command printed.
 *
 * Distinct from Text because the panel has to treat it differently: line
 * breaks and indentation carry the meaning, the block scrolls rather than
 * growing the page, and it stays pinned to the newest line while something is
 * still running. A migration's output rendered as prose is unreadable.
 *
 * Still data, never markup. A plugin sends what the command printed and the
 * panel escapes it, which matters more here than anywhere else on a page:
 * this is the one component whose content came from outside the plugin.
 */
final class Output implements Component
{
    private ?string $title = null;

    private ?string $status = null;

    private ?string $emptyState = null;

    private bool $follow = false;

    private ?int $height = null;

    private function __construct(private readonly string $content)
    {
    }

    public static function make(string $content): self
    {
        return new self($content);
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * The panel's semantic colours: success, danger, warning, info, gray.
     * A failed command's output is worth a red edge; a plugin picking a hex
     * value would be fighting the operator's theme.
     */
    public function status(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function emptyState(string $message): self
    {
        $this->emptyState = $message;

        return $this;
    }

    /**
     * Keep the newest line in view. For a block that a polling page replaces
     * while a command is still running: without it a viewer reads the top of
     * a deploy for as long as the deploy lasts.
     */
    public function follow(bool $follow = true): self
    {
        $this->follow = $follow;

        return $this;
    }

    /**
     * How tall the block is, in lines. The panel clamps it: a component that
     * could be any height is a component that can push everything else off
     * the page.
     */
    public function lines(int $lines): self
    {
        $this->height = $lines;

        return $this;
    }

    public function type(): string
    {
        return 'output';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'type' => $this->type(),
            'content' => $this->content,
            'title' => $this->title,
            'status' => $this->status,
            'empty_state' => $this->emptyState,
            'follow' => $this->follow,
            'lines' => $this->height,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
