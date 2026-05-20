<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

enum NotificationStatus: string
{
    case Queued = "queued";
    case Processing = "processing";
    case Sent = "sent";
    case Delivered = "delivered";
    case RetryScheduled = "retry_scheduled";
    case Dropped = "dropped";
}
