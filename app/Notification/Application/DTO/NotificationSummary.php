<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

final readonly class NotificationSummary
{
    public function __construct(
        public string $id,
        public string $recipientId,
        public string $status,
        public int $attempts,
        public ?string $lastError,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
