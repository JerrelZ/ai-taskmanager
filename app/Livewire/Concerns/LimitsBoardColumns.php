<?php

namespace App\Livewire\Concerns;

/**
 * Caps how many tickets a board column renders, with a per-column "show more"
 * toggle. Keeps long columns short (less DOM, easier scanning) while letting the
 * user reveal the rest on demand. The expanded set lives in component state so
 * it survives live board refreshes.
 */
trait LimitsBoardColumns
{
    /** Tickets shown per status column before the "show more" button appears. */
    public const COLUMN_PREVIEW_LIMIT = 20;

    /** @var array<int, string> Status values the user expanded past the limit. */
    public array $expandedColumns = [];

    public function showMoreColumn(string $status): void
    {
        if (! in_array($status, $this->expandedColumns, true)) {
            $this->expandedColumns[] = $status;
        }
    }

    public function columnIsExpanded(string $status): bool
    {
        return in_array($status, $this->expandedColumns, true);
    }

    public function boardColumnLimit(): int
    {
        return self::COLUMN_PREVIEW_LIMIT;
    }
}
