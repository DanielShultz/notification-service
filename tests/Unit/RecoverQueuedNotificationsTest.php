<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notification\Application\DTO\NotificationForDelivery;
use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Application\DTO\QueuedNotificationForPublishing;
use App\Notification\Application\Port\NotificationDeliveryRepository;
use App\Notification\Application\Port\NotificationQueuePublisher;
use App\Notification\Application\UseCase\RecoverQueuedNotifications;
use App\Notification\Domain\Enum\NotificationTrafficType;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

final class RecoverQueuedNotificationsTest extends TestCase
{
    public function test_republishes_queued_notifications_with_original_traffic_type(): void
    {
        config(["notification.recovery_min_age_seconds" => 600]);

        $repository = new FakeQueuedDeliveryRepository([
            new QueuedNotificationForPublishing(
                "notification-1",
                "batch-1",
                NotificationTrafficType::Transactional,
                1,
            ),
            new QueuedNotificationForPublishing(
                "notification-2",
                "batch-2",
                NotificationTrafficType::Marketing,
                1,
            ),
        ]);
        $publisher = new SpyQueuePublisher();

        $publishedCount = (new RecoverQueuedNotifications(
            $repository,
            $publisher,
            new NullLogger(),
        ))->execute(50);

        $this->assertSame(50, $repository->lastLimit);
        $this->assertSame(600, $repository->lastMinAgeSeconds);
        $this->assertSame(2, $publishedCount);
        $this->assertSame(["notification-1", "notification-2"], $publisher->notificationIds);
        $this->assertSame([
            NotificationTrafficType::Transactional,
            NotificationTrafficType::Marketing,
        ], $publisher->trafficTypes);
    }
}

final class FakeQueuedDeliveryRepository implements NotificationDeliveryRepository
{
    public ?int $lastLimit = null;
    public ?int $lastMinAgeSeconds = null;

    /**
     * @param list<QueuedNotificationForPublishing> $queuedNotifications
     */
    public function __construct(private readonly array $queuedNotifications)
    {
    }

    public function claimForDelivery(string $notificationId): ?NotificationForDelivery
    {
        return null;
    }

    public function findQueuedForPublishing(int $limit, int $minAgeSeconds): array
    {
        $this->lastLimit = $limit;
        $this->lastMinAgeSeconds = $minAgeSeconds;

        return $this->queuedNotifications;
    }

    public function markSent(string $notificationId, int $attempts): void
    {
    }

    public function markDelivered(string $notificationId): void
    {
    }

    public function markDropped(string $notificationId, string $reason): void
    {
    }

    public function markRetryScheduled(string $notificationId, int $attempts, string $reason): void
    {
    }
}

final class SpyQueuePublisher implements NotificationQueuePublisher
{
    /**
     * @var list<string>
     */
    public array $notificationIds = [];

    /**
     * @var list<NotificationTrafficType>
     */
    public array $trafficTypes = [];

    public function publish(QueueMessage $message, NotificationTrafficType $trafficType): void
    {
        $this->notificationIds[] = $message->notificationId;
        $this->trafficTypes[] = $trafficType;
    }

    public function publishRetry(QueueMessage $message, int $delaySeconds): void
    {
    }

    public function publishDeadLetter(QueueMessage $message, string $reason): void
    {
    }
}

final class NullLogger extends AbstractLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
    }
}
