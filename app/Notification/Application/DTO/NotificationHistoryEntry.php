<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

final readonly class NotificationHistoryEntry
{
    public function __construct(
        public string $status,
        public ?string $reason,
        public string $createdAt,
    ) {
    }
}
