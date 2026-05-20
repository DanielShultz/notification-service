<?php

declare(strict_types=1);

namespace App\Notification\Application\UseCase;

use App\Notification\Application\DTO\NotificationBatchStatus;
use App\Notification\Application\Port\NotificationReadRepository;

final readonly class GetNotificationBatchStatus
{
    public function __construct(private NotificationReadRepository $repository) {}

    public function execute(string $batchId): ?NotificationBatchStatus
    {
        return $this->repository->findBatchStatus($batchId);
    }
}
