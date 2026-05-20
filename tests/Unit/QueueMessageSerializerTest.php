<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Domain\Enum\NotificationTrafficType;
use App\Notification\Infrastructure\Queue\RabbitMq\QueueMessageSerializer;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class QueueMessageSerializerTest extends TestCase
{
    public function test_serializes_queue_message_to_json(): void
    {
        $payload = (new QueueMessageSerializer)->serialize(new QueueMessage(
            'notification-1',
            2,
            'batch-1',
            NotificationTrafficType::Transactional,
        ));

        $this->assertJsonStringEqualsJsonString(
            '{"notification_id":"notification-1","attempt":2,"batch_id":"batch-1","traffic_type":"transactional"}',
            $payload,
        );
    }

    public function test_deserializes_queue_message_from_json(): void
    {
        $message = (new QueueMessageSerializer)->deserialize(
            '{"notification_id":"notification-1","attempt":2,"batch_id":"batch-1","traffic_type":"transactional"}',
        );

        $this->assertSame('notification-1', $message->notificationId);
        $this->assertSame(2, $message->attempt);
        $this->assertSame('batch-1', $message->batchId);
        $this->assertSame(NotificationTrafficType::Transactional, $message->trafficType);
    }

    public function test_deserializes_queue_message_without_traffic_type_as_default_traffic(): void
    {
        $message = (new QueueMessageSerializer)->deserialize(
            '{"notification_id":"notification-1","attempt":2,"batch_id":"batch-1"}',
        );

        $this->assertSame('batch-1', $message->batchId);
        $this->assertSame(NotificationTrafficType::Marketing, $message->trafficType);
    }

    public function test_rejects_invalid_queue_message_payload(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new QueueMessageSerializer)->deserialize('{"notification_id":"notification-1"}');
    }

    public function test_rejects_non_integer_attempt(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new QueueMessageSerializer)->deserialize(
            '{"notification_id":"notification-1","attempt":"2","batch_id":"batch-1","traffic_type":"marketing"}',
        );
    }

    public function test_rejects_attempt_less_than_one(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new QueueMessageSerializer)->deserialize(
            '{"notification_id":"notification-1","attempt":0,"batch_id":"batch-1","traffic_type":"marketing"}',
        );
    }
}
