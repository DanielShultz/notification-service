<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\DTO\CreateNotificationBatchRequest;

interface NotificationBatchRepository
{
    /**
     * @param  array<string, string>  $notificationIds
     */
    public function createBatch(CreateNotificationBatchRequest $request, string $batchId, array $notificationIds): void;

    public function findBatchIdByIdempotencyKey(string $idempotencyKey): ?string;

    public function countNotifications(string $batchId): int;
}
