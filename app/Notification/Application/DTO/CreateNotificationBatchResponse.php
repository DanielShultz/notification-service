<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

final readonly class CreateNotificationBatchResponse
{
    public function __construct(
        public string $batchId,
        public int $notificationCount,
        public bool $duplicate,
    ) {
    }
}

