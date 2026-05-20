<?php

declare(strict_types=1);

namespace App\Notification\Application\UseCase;

use App\Notification\Application\DTO\CreateNotificationBatchRequest;
use App\Notification\Application\DTO\CreateNotificationBatchResponse;
use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Application\Exception\IdempotencyKeyReserved;
use App\Notification\Application\Port\IdempotencyStore;
use App\Notification\Application\Port\NotificationBatchRepository;
use App\Notification\Application\Port\NotificationQueuePublisher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class CreateNotificationBatch
{
    private const IDEMPOTENCY_TTL_SECONDS = 86400;

    private const RESERVED_LOOKUP_ATTEMPTS = 3;

    private const RESERVED_LOOKUP_DELAY_MICROSECONDS = 20000;

    public function __construct(
        private NotificationBatchRepository $repository,
        private NotificationQueuePublisher $queuePublisher,
        private IdempotencyStore $idempotencyStore,
        private LoggerInterface $logger,
    ) {}

    public function execute(CreateNotificationBatchRequest $request): CreateNotificationBatchResponse
    {
        $existingBatchId = $this->idempotencyStore->getResultId($request->idempotencyKey)
            ?? $this->repository->findBatchIdByIdempotencyKey($request->idempotencyKey);

        if ($existingBatchId !== null) {
            return new CreateNotificationBatchResponse(
                $existingBatchId,
                $this->repository->countNotifications($existingBatchId),
                true,
            );
        }

        if ($this->idempotencyStore->reserve($request->idempotencyKey, self::IDEMPOTENCY_TTL_SECONDS) === false) {
            return $this->existingResponse($request->idempotencyKey);
        }

        $batchId = (string) Str::uuid();
        $notificationIds = [];

        foreach ($request->recipientIds as $recipientId) {
            $notificationIds[$recipientId] = (string) Str::uuid();
        }

        try {
            $this->repository->createBatch($request, $batchId, $notificationIds);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return $this->existingResponse($request->idempotencyKey);
        }

        $publishedCount = 0;

        try {
            foreach ($notificationIds as $notificationId) {
                $this->queuePublisher->publish(
                    new QueueMessage($notificationId, 1, $batchId, $request->trafficType),
                    $request->trafficType,
                );
                $publishedCount++;
            }
        } catch (Throwable $exception) {
            $this->logger->error('Notification batch publishing interrupted', [
                'batch_id' => $batchId,
                'notification_id' => $notificationId ?? null,
                'published_count' => $publishedCount,
                'total_count' => count($notificationIds),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->idempotencyStore->remember($request->idempotencyKey, $batchId, self::IDEMPOTENCY_TTL_SECONDS);
        $this->logger->info('Notification batch created', [
            'batch_id' => $batchId,
            'notification_count' => count($notificationIds),
        ]);

        return new CreateNotificationBatchResponse($batchId, count($notificationIds), false);
    }

    private function existingResponse(string $idempotencyKey): CreateNotificationBatchResponse
    {
        for ($attempt = 1; $attempt <= self::RESERVED_LOOKUP_ATTEMPTS; $attempt++) {
            $existingBatchId = $this->idempotencyStore->getResultId($idempotencyKey)
                ?? $this->repository->findBatchIdByIdempotencyKey($idempotencyKey);

            if ($existingBatchId !== null) {
                return new CreateNotificationBatchResponse(
                    $existingBatchId,
                    $this->repository->countNotifications($existingBatchId),
                    true,
                );
            }

            usleep(self::RESERVED_LOOKUP_DELAY_MICROSECONDS);
        }

        throw new IdempotencyKeyReserved;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23505';
    }
}
