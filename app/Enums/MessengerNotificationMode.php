<?php

namespace App\Enums;

enum MessengerNotificationMode: string
{
    case Realtime = 'realtime';
    case Digest = 'digest';

    public function label(): string
    {
        return match ($this) {
            self::Realtime => 'Realtime',
            self::Digest => 'Periodieke samenvatting',
        };
    }
}
