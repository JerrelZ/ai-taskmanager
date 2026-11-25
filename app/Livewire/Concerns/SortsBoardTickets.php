<?php

namespace App\Livewire\Concerns;

use App\Models\Task;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Lets a board show its columns in another order than the hand-dragged one —
 * e.g. newest first. The manual order stays the default and is the only mode in
 * which dragging re-ranks tickets; in every other mode the order is derived, so
 * a drag can only move a ticket to another status column.
 */
trait SortsBoardTickets
{
    public const SORT_MANUAL = 'manual';

    #[Url(as: 'sort')]
    public string $sortBy = self::SORT_MANUAL;

    /**
     * Available sort modes with their label, in menu order.
     *
     * @return array<string, string>
     */
    public function sortOptions(): array
    {
        return [
            self::SORT_MANUAL => __('Handmatig'),
            'created_desc' => __('Nieuwste eerst'),
            'created_asc' => __('Oudste eerst'),
            'updated_desc' => __('Laatst bijgewerkt'),
            'due_asc' => __('Deadline'),
            'priority' => __('Prioriteit'),
        ];
    }

    /**
     * The active sort mode; an unknown value (e.g. a hand-edited URL) falls
     * back to the manual order.
     */
    public function activeSort(): string
    {
        return array_key_exists($this->sortBy, $this->sortOptions()) ? $this->sortBy : self::SORT_MANUAL;
    }

    public function isManualSort(): bool
    {
        return $this->activeSort() === self::SORT_MANUAL;
    }

    public function sortLabel(): string
    {
        return $this->sortOptions()[$this->activeSort()];
    }

    /**
     * Re-order tickets that were fetched in manual (position) order. Ties keep
     * that manual order, so e.g. equal priorities stay ranked as dragged.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    protected function applyTicketSort(Collection $tasks): Collection
    {
        $timestamp = fn (Task $task, string $column): int => $task->{$column}?->getTimestamp() ?? 0;

        $sorted = match ($this->activeSort()) {
            'created_desc' => $tasks->sortBy([
                fn (Task $a, Task $b) => [$timestamp($b, 'created_at'), $b->id] <=> [$timestamp($a, 'created_at'), $a->id],
            ]),
            'created_asc' => $tasks->sortBy([
                fn (Task $a, Task $b) => [$timestamp($a, 'created_at'), $a->id] <=> [$timestamp($b, 'created_at'), $b->id],
            ]),
            'updated_desc' => $tasks->sortBy([
                fn (Task $a, Task $b) => $timestamp($b, 'updated_at') <=> $timestamp($a, 'updated_at'),
            ]),
            // Tickets without a deadline go last.
            'due_asc' => $tasks->sortBy([
                fn (Task $a, Task $b) => [$a->due_date === null, $timestamp($a, 'due_date')] <=> [$b->due_date === null, $timestamp($b, 'due_date')],
            ]),
            'priority' => $tasks->sortBy([
                fn (Task $a, Task $b) => $a->priority->weight() <=> $b->priority->weight(),
            ]),
            default => $tasks,
        };

        return $sorted->values();
    }
}
