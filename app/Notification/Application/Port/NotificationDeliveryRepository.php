<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\DTO\NotificationForDelivery;
use App\Notification\Application\DTO\QueuedNotificationForPublishing;

interface NotificationDeliveryRepository
{
    public function claimForDelivery(string $notificationId): ?NotificationForDelivery;

    /**
     * @return list<QueuedNotificationForPublishing>
     */
    public function findQueuedForPublishing(int $limit, int $minAgeSeconds): array;

    public function markSent(string $notificationId, int $attempts): void;

    public function markDelivered(string $notificationId): void;

    public function markDropped(string $notificationId, string $reason): void;

    public function markRetryScheduled(string $notificationId, int $attempts, string $reason): void;
}
