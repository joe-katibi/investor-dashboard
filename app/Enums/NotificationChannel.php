<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum NotificationChannel: string implements HasLabel
{
    case InApp = 'database';
    case Mail = 'mail';
    case Sms = 'sms';

    public function getLabel(): string
    {
        return match ($this) {
            self::InApp => 'In-app (real-time)',
            self::Mail => 'Email',
            self::Sms => 'SMS',
        };
    }
}
