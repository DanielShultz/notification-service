<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationTrafficType;

final readonly class CreateNotificationBatchRequest
{
    /**
     * @param  array<int, string>  $recipientIds
     */
    public function __construct(
        public NotificationChannel $channel,
        public NotificationTrafficType $trafficType,
        public string $message,
        public array $recipientIds,
        public string $idempotencyKey,
    ) {}
}
