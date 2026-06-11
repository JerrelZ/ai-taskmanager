<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actief',
            self::OnHold => 'On hold',
            self::Archived => 'Gearchiveerd',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::OnHold => 'amber',
            self::Archived => 'zinc',
        };
    }
}
