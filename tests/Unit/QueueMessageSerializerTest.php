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
        $payload = (new QueueMessageSerializer())->serialize(new QueueMessage(
            "notification-1",
            2,
            "trace-1",
            NotificationTrafficType::Transactional,
        ));

        $this->assertJsonStringEqualsJsonString(
            '{"notification_id":"notification-1","attempt":2,"trace_id":"trace-1","traffic_type":"transactional"}',
            $payload,
        );
    }

    public function test_deserializes_queue_message_from_json(): void
    {
        $message = (new QueueMessageSerializer())->deserialize(
            '{"notification_id":"notification-1","attempt":2,"trace_id":"trace-1","traffic_type":"transactional"}',
        );

        $this->assertSame("notification-1", $message->notificationId);
        $this->assertSame(2, $message->attempt);
        $this->assertSame("trace-1", $message->traceId);
        $this->assertSame(NotificationTrafficType::Transactional, $message->trafficType);
    }

    public function test_deserializes_legacy_queue_message_as_default_traffic(): void
    {
        $message = (new QueueMessageSerializer())->deserialize(
            '{"notification_id":"notification-1","attempt":2,"trace_id":"trace-1"}',
        );

        $this->assertSame(NotificationTrafficType::Marketing, $message->trafficType);
    }

    public function test_rejects_invalid_queue_message_payload(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new QueueMessageSerializer())->deserialize('{"notification_id":"notification-1"}');
    }

    public function test_rejects_non_integer_attempt(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new QueueMessageSerializer())->deserialize(
            '{"notification_id":"notification-1","attempt":"2","trace_id":"trace-1","traffic_type":"marketing"}',
        );
    }

    public function test_rejects_attempt_less_than_one(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new QueueMessageSerializer())->deserialize(
            '{"notification_id":"notification-1","attempt":0,"trace_id":"trace-1","traffic_type":"marketing"}',
        );
    }
}
