<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notification\Application\DTO\NotificationForDelivery;
use App\Notification\Application\DTO\ProviderDeliveryResult;
use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Application\Port\NotificationDeliveryRepository;
use App\Notification\Application\Port\NotificationProvider;
use App\Notification\Application\Port\NotificationQueuePublisher;
use App\Notification\Application\UseCase\ProcessNotificationDelivery;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationStatus;
use App\Notification\Domain\Enum\NotificationTrafficType;
use App\Notification\Domain\Enum\ProviderDeliveryStatus;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

final class ProcessNotificationDeliveryTest extends TestCase
{
    public function test_marks_notification_as_delivered_after_provider_success(): void
    {
        $repository = new FakeDeliveryRepository($this->notification(NotificationStatus::Queued));

        (new ProcessNotificationDelivery(
            $repository,
            new SpyNotificationProvider(new ProviderDeliveryResult(ProviderDeliveryStatus::Delivered)),
            new FakeQueuePublisher(),
            new ArrayLogger(),
        ))->execute($this->message());

        $this->assertTrue($repository->sentMarked);
        $this->assertTrue($repository->deliveredMarked);
        $this->assertSame(1, $repository->sentAttempts);
    }

    public function test_skips_provider_for_already_delivered_notification(): void
    {
        $repository = new FakeDeliveryRepository($this->notification(NotificationStatus::Delivered));
        $provider = new SpyNotificationProvider();

        (new ProcessNotificationDelivery(
            $repository,
            $provider,
            new FakeQueuePublisher(),
            new ArrayLogger(),
        ))->execute($this->message(2));

        $this->assertSame(0, $provider->sendCalls);
        $this->assertFalse($repository->sentMarked);
    }

    public function test_marks_already_sent_notification_as_delivered_without_provider_retry(): void
    {
        $repository = new FakeDeliveryRepository($this->notification(NotificationStatus::Sent));
        $provider = new SpyNotificationProvider();

        (new ProcessNotificationDelivery(
            $repository,
            $provider,
            new FakeQueuePublisher(),
            new ArrayLogger(),
        ))->execute($this->message(2));

        $this->assertSame(0, $provider->sendCalls);
        $this->assertFalse($repository->sentMarked);
        $this->assertTrue($repository->deliveredMarked);
    }

    public function test_skips_provider_when_notification_is_already_claimed(): void
    {
        $repository = new FakeDeliveryRepository(null);
        $provider = new SpyNotificationProvider();

        (new ProcessNotificationDelivery(
            $repository,
            $provider,
            new FakeQueuePublisher(),
            new ArrayLogger(),
        ))->execute($this->message());

        $this->assertSame(0, $provider->sendCalls);
        $this->assertFalse($repository->sentMarked);
        $this->assertFalse($repository->deliveredMarked);
    }

    public function test_publishes_retry_after_temporary_provider_failure(): void
    {
        config(["notification.max_attempts" => 3]);
        config(["notification.retry_delay_seconds" => 15]);

        $repository = new FakeDeliveryRepository($this->notification(NotificationStatus::Queued));
        $publisher = new FakeQueuePublisher();

        (new ProcessNotificationDelivery(
            $repository,
            new SpyNotificationProvider(new ProviderDeliveryResult(
                ProviderDeliveryStatus::TemporaryFailure,
                "Provider is temporarily unavailable",
            )),
            $publisher,
            new ArrayLogger(),
        ))->execute($this->message());

        $this->assertTrue($repository->retryScheduled);
        $this->assertFalse($repository->sentMarked);
        $this->assertSame(1, $publisher->retryMessages);
        $this->assertSame(2, $publisher->lastRetryMessage?->attempt);
        $this->assertSame(NotificationTrafficType::Transactional, $publisher->lastRetryMessage?->trafficType);
        $this->assertSame(15, $publisher->lastRetryDelaySeconds);
    }

    public function test_moves_notification_to_dlq_after_permanent_provider_failure(): void
    {
        $repository = new FakeDeliveryRepository($this->notification(NotificationStatus::Queued));
        $publisher = new FakeQueuePublisher();

        (new ProcessNotificationDelivery(
            $repository,
            new SpyNotificationProvider(new ProviderDeliveryResult(
                ProviderDeliveryStatus::PermanentFailure,
                "Provider rejected recipient",
            )),
            $publisher,
            new ArrayLogger(),
        ))->execute($this->message());

        $this->assertFalse($repository->sentMarked);
        $this->assertTrue($repository->droppedMarked);
        $this->assertSame("Provider rejected recipient", $repository->dropReason);
        $this->assertSame(1, $publisher->deadLetterMessages);
        $this->assertSame("Provider rejected recipient", $publisher->lastDeadLetterReason);
    }

    public function test_moves_notification_to_dlq_after_max_temporary_attempts(): void
    {
        config(["notification.max_attempts" => 3]);

        $repository = new FakeDeliveryRepository($this->notification(NotificationStatus::Queued));
        $publisher = new FakeQueuePublisher();

        (new ProcessNotificationDelivery(
            $repository,
            new SpyNotificationProvider(new ProviderDeliveryResult(
                ProviderDeliveryStatus::TemporaryFailure,
                "Provider is temporarily unavailable",
            )),
            $publisher,
            new ArrayLogger(),
        ))->execute($this->message(3));

        $this->assertFalse($repository->sentMarked);
        $this->assertTrue($repository->droppedMarked);
        $this->assertFalse($repository->retryScheduled);
        $this->assertSame(0, $publisher->retryMessages);
        $this->assertSame(1, $publisher->deadLetterMessages);
    }

    private function notification(NotificationStatus $status): NotificationForDelivery
    {
        return new NotificationForDelivery(
            "notification-1",
            "batch-1",
            "recipient-1",
            NotificationChannel::Sms,
            NotificationTrafficType::Transactional,
            $status,
            "Access code: 1234",
            1,
        );
    }

    private function message(int $attempt = 1): QueueMessage
    {
        return new QueueMessage("notification-1", $attempt, "trace-1", NotificationTrafficType::Transactional);
    }
}

final class FakeDeliveryRepository implements NotificationDeliveryRepository
{
    public bool $sentMarked = false;
    public bool $deliveredMarked = false;
    public bool $droppedMarked = false;
    public bool $retryScheduled = false;
    public ?int $sentAttempts = null;
    public ?string $dropReason = null;

    public function __construct(private readonly ?NotificationForDelivery $notification)
    {
    }

    public function claimForDelivery(string $notificationId): ?NotificationForDelivery
    {
        return $this->notification;
    }

    public function findQueuedForPublishing(int $limit, int $minAgeSeconds): array
    {
        return [];
    }

    public function markSent(string $notificationId, int $attempts): void
    {
        $this->sentMarked = true;
        $this->sentAttempts = $attempts;
    }

    public function markDelivered(string $notificationId): void
    {
        $this->deliveredMarked = true;
    }

    public function markDropped(string $notificationId, string $reason): void
    {
        $this->droppedMarked = true;
        $this->dropReason = $reason;
    }

    public function markRetryScheduled(string $notificationId, int $attempts, string $reason): void
    {
        $this->retryScheduled = true;
    }
}

final class SpyNotificationProvider implements NotificationProvider
{
    public int $sendCalls = 0;

    public function __construct(
        private readonly ProviderDeliveryResult $result = new ProviderDeliveryResult(ProviderDeliveryStatus::Delivered),
    ) {
    }

    public function send(NotificationForDelivery $notification): ProviderDeliveryResult
    {
        $this->sendCalls++;

        return $this->result;
    }
}

final class FakeQueuePublisher implements NotificationQueuePublisher
{
    public int $retryMessages = 0;
    public int $deadLetterMessages = 0;
    public ?QueueMessage $lastRetryMessage = null;
    public ?int $lastRetryDelaySeconds = null;
    public ?string $lastDeadLetterReason = null;

    public function publish(QueueMessage $message, NotificationTrafficType $trafficType): void
    {
    }

    public function publishRetry(QueueMessage $message, int $delaySeconds): void
    {
        $this->retryMessages++;
        $this->lastRetryMessage = $message;
        $this->lastRetryDelaySeconds = $delaySeconds;
    }

    public function publishDeadLetter(QueueMessage $message, string $reason): void
    {
        $this->deadLetterMessages++;
        $this->lastDeadLetterReason = $reason;
    }
}

final class ArrayLogger extends AbstractLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
    }
}
