<?php

namespace App\Enums;

/**
 * How ready a ticket is to be handed to Claude Code as a paste-ready prompt.
 */
enum TaskReadiness: string
{
    case Ready = 'ready';

    case Almost = 'almost';

    case NotReady = 'not_ready';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Klaar',
            self::Almost => 'Bijna klaar',
            self::NotReady => 'Niet klaar',
        };
    }

    /**
     * Flux badge/dot color for this readiness level.
     */
    public function color(): string
    {
        return match ($this) {
            self::Ready => 'green',
            self::Almost => 'amber',
            self::NotReady => 'zinc',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Ready => 'check-circle',
            self::Almost => 'exclamation-circle',
            self::NotReady => 'minus-circle',
        };
    }

    /**
     * A ticket counts as paste-ready only when fully ready.
     */
    public function isReady(): bool
    {
        return $this === self::Ready;
    }
}
