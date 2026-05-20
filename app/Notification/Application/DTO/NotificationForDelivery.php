<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationStatus;
use App\Notification\Domain\Enum\NotificationTrafficType;

final readonly class NotificationForDelivery
{
    public function __construct(
        public string $id,
        public string $batchId,
        public string $recipientId,
        public NotificationChannel $channel,
        public NotificationTrafficType $trafficType,
        public NotificationStatus $status,
        public string $message,
        public int $attempts,
    ) {}
}
