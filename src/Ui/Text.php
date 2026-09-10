<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * A block of prose.
 *
 * Markdown is rendered by the panel from a restricted subset: emphasis,
 * lists, links, inline code and headings. Raw HTML in the source is escaped
 * rather than rendered, so a plugin cannot inject markup into the panel by
 * way of a text block.
 */
final class Text implements Component
{
    private function __construct(
        private readonly string $content,
        private readonly bool $markdown,
    ) {
    }

    public static function make(string $content): self
    {
        return new self($content, false);
    }

    public static function markdown(string $content): self
    {
        return new self($content, true);
    }

    public function type(): string
    {
        return 'text';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'content' => $this->content,
            'markdown' => $this->markdown,
        ];
    }
}
