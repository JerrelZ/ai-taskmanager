<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Backlog => 'Backlog',
            self::Todo => 'Todo',
            self::InProgress => 'In Progress',
            self::Done => 'Done',
            self::Canceled => 'Canceled',
        };
    }

    /**
     * Flux badge/dot color for this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Backlog => 'zinc',
            self::Todo => 'blue',
            self::InProgress => 'amber',
            self::Done => 'green',
            self::Canceled => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Backlog => 'circle-dashed',
            self::Todo => 'circle',
            self::InProgress => 'clock',
            self::Done => 'check-circle',
            self::Canceled => 'x-circle',
        };
    }

    /**
     * A task in one of these statuses counts as completed.
     */
    public function isComplete(): bool
    {
        return $this === self::Done || $this === self::Canceled;
    }

    /**
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return self::cases();
    }
}
