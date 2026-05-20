<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Notification\Domain\Enum\NotificationTrafficType;

final readonly class QueueMessage
{
    public function __construct(
        public string $notificationId,
        public int $attempt,
        public string $batchId,
        public NotificationTrafficType $trafficType,
    ) {}
}
