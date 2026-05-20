<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Queue\InMemory;

use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Application\Port\NotificationQueuePublisher;
use App\Notification\Domain\Enum\NotificationTrafficType;
use Psr\Log\LoggerInterface;

final readonly class LogNotificationQueuePublisher implements NotificationQueuePublisher
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function publish(QueueMessage $message, NotificationTrafficType $trafficType): void
    {
        $this->logger->info("Notification queued for delivery", [
            "notification_id" => $message->notificationId,
            "attempt" => $message->attempt,
            "trace_id" => $message->traceId,
            "traffic_type" => $trafficType->value,
        ]);
    }

    public function publishRetry(QueueMessage $message, int $delaySeconds): void
    {
        $this->logger->warning("Notification queued for retry", [
            "notification_id" => $message->notificationId,
            "attempt" => $message->attempt,
            "trace_id" => $message->traceId,
            "traffic_type" => $message->trafficType->value,
            "delay_seconds" => $delaySeconds,
        ]);
    }

    public function publishDeadLetter(QueueMessage $message, string $reason): void
    {
        $this->logger->warning("Notification moved to dead letter queue", [
            "notification_id" => $message->notificationId,
            "attempt" => $message->attempt,
            "trace_id" => $message->traceId,
            "traffic_type" => $message->trafficType->value,
            "reason" => $reason,
        ]);
    }
}
