<?php

declare(strict_types=1);

namespace App\Notification\Application\UseCase;

use App\Notification\Application\DTO\NotificationHistory;
use App\Notification\Application\Port\NotificationReadRepository;

final readonly class GetNotificationHistory
{
    public function __construct(private NotificationReadRepository $repository) {}

    public function execute(string $notificationId): ?NotificationHistory
    {
        return $this->repository->findNotificationHistory($notificationId);
    }
}
