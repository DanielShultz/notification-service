<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

final readonly class NotificationHistory
{
    /**
     * @param list<NotificationHistoryEntry> $history
     */
    public function __construct(
        public string $id,
        public string $batchId,
        public string $recipientId,
        public string $channel,
        public string $trafficType,
        public string $status,
        public int $attempts,
        public ?string $lastError,
        public array $history,
    ) {
    }
}
