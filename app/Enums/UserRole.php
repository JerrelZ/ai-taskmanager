<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Member = 'member';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Beheerder',
            self::Member => 'Teamlid',
            self::Client => 'Klant',
        };
    }

    /**
     * Internal team members (full access to all projects).
     */
    public function isTeam(): bool
    {
        return $this === self::Admin || $this === self::Member;
    }
}
