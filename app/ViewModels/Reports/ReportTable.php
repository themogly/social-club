<?php

namespace App\ViewModels\Reports;

/**
 * The one shared shape every report produces: a titled, sortable table of RAW rows
 * (integers for money/weight so a total is exact) plus a totals row. Both the screen
 * and every export render from this object, so they are the same figures by
 * construction — the export can never re-derive and drift from what was shown.
 */
class ReportTable
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows  each row keyed by column key, raw values
     * @param  array<string, int|float|string|null>  $totals  keyed by column key, raw values
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $totals = [],
        public readonly ?string $empty = null,
        public readonly ?string $emptyHint = null,
        public readonly ?string $defaultSort = null,
        public readonly string $defaultSortDir = 'desc',
        public readonly bool $sortable = false,
        public readonly ?string $note = null,
    ) {}

    public function hasRows(): bool
    {
        return $this->rows !== [];
    }

    public function hasTotals(): bool
    {
        return $this->totals !== [];
    }

    public function column(string $key): ?ReportColumn
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    /** The effective sort column key, honouring a request but falling back to the default. */
    public function resolveSort(?string $key): ?string
    {
        $key ??= $this->defaultSort;
        $column = $key !== null ? $this->column($key) : null;

        return ($column !== null && $column->sortable) ? $key : $this->defaultSort;
    }

    /**
     * A copy sorted by a column's RAW value — exact for money/weight (integers), so the
     * order shown and the order exported never disagree. Unknown/unsortable keys are ignored.
     */
    public function sortedBy(?string $key, string $dir = 'desc'): self
    {
        $key = $this->resolveSort($key);
        $column = $key !== null ? $this->column($key) : null;

        if ($column === null || ! $this->hasRows()) {
            return $this;
        }

        $rows = $this->rows;
        usort($rows, function (array $a, array $b) use ($column, $dir): int {
            $cmp = $column->sortValue($a[$column->key] ?? null) <=> $column->sortValue($b[$column->key] ?? null);

            return $dir === 'asc' ? $cmp : -$cmp;
        });

        return new self(
            $this->key, $this->title, $this->columns, $rows, $this->totals,
            $this->empty, $this->emptyHint, $key, $dir, $this->sortable, $this->note,
        );
    }
}
