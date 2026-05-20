<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\DTO\NotificationBatchStatus;
use App\Notification\Application\DTO\NotificationHistory;

interface NotificationReadRepository
{
    public function findBatchStatus(string $batchId): ?NotificationBatchStatus;

    public function findNotificationHistory(string $notificationId): ?NotificationHistory;

    /**
     * @return list<NotificationHistory>
     */
    public function findRecipientNotifications(string $recipientId): array;
}
