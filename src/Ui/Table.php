<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * Rows and columns.
 *
 * The plugin supplies data, not markup. The panel renders it with its own
 * table, so sorting, dark mode, responsive stacking and escaping are its
 * problem rather than the plugin's.
 */
final class Table implements Component
{
    /** @var list<Column> */
    private array $columns = [];

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var list<Action> */
    private array $rowActions = [];

    private string $emptyMessage = 'Nothing to show yet.';

    private ?string $emptyIcon = null;

    private ?string $rowKey = null;

    /** @var array{page: int, per_page: int, total: int}|null */
    private ?array $pagination = null;

    public static function make(): self
    {
        return new self();
    }

    public function columns(Column ...$columns): self
    {
        $this->columns = [...$this->columns, ...$columns];

        return $this;
    }

    /**
     * @param list<array<string, mixed>> $rows keyed by column key
     */
    public function rows(array $rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    /**
     * Which column uniquely identifies a row. The panel sends it back as the
     * action argument "key" when a row action runs.
     */
    public function keyedBy(string $column): self
    {
        $this->rowKey = $column;

        return $this;
    }

    public function rowActions(Action ...$actions): self
    {
        $this->rowActions = [...$this->rowActions, ...$actions];

        return $this;
    }

    public function emptyState(string $message, ?string $icon = null): self
    {
        $this->emptyMessage = $message;
        $this->emptyIcon = $icon;

        return $this;
    }

    /**
     * Tell the panel this is one page of a larger set, so it draws a pager
     * and sends "page" back as a request parameter. Without this the panel
     * assumes it has everything.
     */
    public function paginated(int $total, int $perPage, int $page = 1): self
    {
        $this->pagination = ['page' => $page, 'per_page' => $perPage, 'total' => $total];

        return $this;
    }

    /** @return list<Action> */
    public function actions(): array
    {
        return $this->rowActions;
    }

    public function type(): string
    {
        return 'table';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'type' => $this->type(),
            'columns' => $this->columns,
            'rows' => $this->rows,
            'row_key' => $this->rowKey,
            'row_actions' => $this->rowActions ?: null,
            'empty' => ['message' => $this->emptyMessage, 'icon' => $this->emptyIcon],
            'pagination' => $this->pagination,
        ], static fn ($value) => $value !== null);
    }
}
