<?php

declare(strict_types=1);

namespace App\Notification\Application\UseCase;

use App\Notification\Application\DTO\NotificationHistory;
use App\Notification\Application\Port\NotificationReadRepository;

final readonly class GetRecipientNotifications
{
    public function __construct(private NotificationReadRepository $repository) {}

    /**
     * @return list<NotificationHistory>
     */
    public function execute(string $recipientId): array
    {
        return $this->repository->findRecipientNotifications($recipientId);
    }
}
