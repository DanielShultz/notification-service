<?php

declare(strict_types=1);

namespace App\Notification\Application\UseCase;

use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Application\Port\NotificationDeliveryRepository;
use App\Notification\Application\Port\NotificationQueuePublisher;
use Psr\Log\LoggerInterface;

final readonly class RecoverQueuedNotifications
{
    public function __construct(
        private NotificationDeliveryRepository $repository,
        private NotificationQueuePublisher $queuePublisher,
        private LoggerInterface $logger,
    ) {
    }

    public function execute(int $limit = 100): int
    {
        $notifications = $this->repository->findQueuedForPublishing(
            $limit,
            (int)config("notification.recovery_min_age_seconds"),
        );

        foreach ($notifications as $notification) {
            $this->queuePublisher->publish(
                new QueueMessage(
                    $notification->notificationId,
                    max(1, $notification->attempt),
                    $notification->batchId,
                    $notification->trafficType,
                ),
                $notification->trafficType,
            );
        }

        $this->logger->info("Queued notifications recovery completed", [
            "published_count" => count($notifications),
        ]);

        return count($notifications);
    }
}
