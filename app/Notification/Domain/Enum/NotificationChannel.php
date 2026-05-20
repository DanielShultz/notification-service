<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
}
