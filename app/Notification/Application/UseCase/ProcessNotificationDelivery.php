<?php

declare(strict_types=1);

namespace App\Notification\Application\UseCase;

use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Application\Port\NotificationDeliveryRepository;
use App\Notification\Application\Port\NotificationProvider;
use App\Notification\Application\Port\NotificationQueuePublisher;
use App\Notification\Domain\Enum\NotificationStatus;
use App\Notification\Domain\Enum\ProviderDeliveryStatus;
use Psr\Log\LoggerInterface;

final readonly class ProcessNotificationDelivery
{
    public function __construct(
        private NotificationDeliveryRepository $repository,
        private NotificationProvider $provider,
        private NotificationQueuePublisher $queuePublisher,
        private LoggerInterface $logger,
    ) {}

    public function execute(QueueMessage $message): void
    {
        $notification = $this->repository->claimForDelivery($message->notificationId);

        if ($notification === null) {
            $this->logger->warning('Notification delivery message skipped', [
                'notification_id' => $message->notificationId,
                'attempt' => $message->attempt,
                'batch_id' => $message->batchId,
            ]);

            return;
        }

        if ($this->isFinalStatus($notification->status)) {
            $this->logger->info('Notification delivery message already finalized', [
                'notification_id' => $message->notificationId,
                'batch_id' => $notification->batchId,
                'status' => $notification->status->value,
                'attempt' => $message->attempt,
            ]);

            return;
        }

        if ($notification->status === NotificationStatus::Sent) {
            $this->logger->warning('Notification delivery message was already sent; marking as delivered without provider retry', [
                'notification_id' => $message->notificationId,
                'batch_id' => $notification->batchId,
                'attempt' => $message->attempt,
            ]);
            $this->repository->markDelivered($notification->id);

            return;
        }

        $result = $this->provider->send($notification);

        if ($result->status === ProviderDeliveryStatus::Delivered) {
            $this->repository->markSentAndDelivered($notification->id, $message->attempt);

            return;
        }

        $reason = $result->errorMessage ?? $result->status->value;

        if ($result->status === ProviderDeliveryStatus::PermanentFailure) {
            $this->repository->markDropped($notification->id, $reason);
            $this->queuePublisher->publishDeadLetter($message, $reason);

            return;
        }

        $maxAttempts = (int) config('notification.max_attempts');
        $nextAttempt = $message->attempt + 1;

        if ($message->attempt >= $maxAttempts) {
            $this->repository->markDropped($notification->id, $reason);
            $this->queuePublisher->publishDeadLetter($message, $reason);

            return;
        }

        $this->repository->markRetryScheduled($notification->id, $message->attempt, $reason);
        $this->queuePublisher->publishRetry(
            new QueueMessage($message->notificationId, $nextAttempt, $message->batchId, $notification->trafficType),
            (int) config('notification.retry_delay_seconds'),
        );
    }

    private function isFinalStatus(NotificationStatus $status): bool
    {
        return in_array($status, [
            NotificationStatus::Delivered,
            NotificationStatus::Dropped,
        ], true);
    }
}
