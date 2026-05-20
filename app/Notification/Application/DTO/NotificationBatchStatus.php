<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

final readonly class NotificationBatchStatus
{
    /**
     * @param array<string, int> $statusCounts
     * @param list<NotificationSummary> $notifications
     */
    public function __construct(
        public string $id,
        public string $channel,
        public string $trafficType,
        public int $recipientCount,
        public array $statusCounts,
        public array $notifications,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
