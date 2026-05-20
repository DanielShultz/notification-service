<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

enum NotificationTrafficType: string
{
    case Transactional = "transactional";
    case Marketing = "marketing";
}

