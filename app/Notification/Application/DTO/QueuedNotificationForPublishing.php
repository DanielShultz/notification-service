<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Notification\Domain\Enum\NotificationTrafficType;

final readonly class QueuedNotificationForPublishing
{
    public function __construct(
        public string $notificationId,
        public string $batchId,
        public NotificationTrafficType $trafficType,
        public int $attempt,
    ) {
    }
}
