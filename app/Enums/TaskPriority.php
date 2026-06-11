<?php

namespace App\Enums;

enum TaskPriority: string
{
    case None = 'none';
    case Urgent = 'urgent';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No priority',
            self::Urgent => 'Urgent',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    /**
     * Flux color for badges/icons.
     */
    public function color(): string
    {
        return match ($this) {
            self::None => 'zinc',
            self::Urgent => 'red',
            self::High => 'orange',
            self::Medium => 'amber',
            self::Low => 'zinc',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::None => 'minus',
            self::Urgent => 'exclamation-triangle',
            self::High => 'chevron-double-up',
            self::Medium => 'chevron-up',
            self::Low => 'chevron-down',
        };
    }

    /**
     * Sort weight, lower is more important (after None).
     */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 1,
            self::High => 2,
            self::Medium => 3,
            self::Low => 4,
            self::None => 5,
        };
    }
}
