<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Queue\RabbitMq;

use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Domain\Enum\NotificationTrafficType;
use JsonException;
use UnexpectedValueException;

final readonly class QueueMessageSerializer
{
    /**
     * @throws JsonException
     */
    public function serialize(QueueMessage $message): string
    {
        return json_encode([
            "notification_id" => $message->notificationId,
            "attempt" => $message->attempt,
            "trace_id" => $message->traceId,
            "traffic_type" => $message->trafficType->value,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     * @throws UnexpectedValueException
     */
    public function deserialize(string $payload): QueueMessage
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (
            !is_array($data)
            || !isset($data["notification_id"], $data["attempt"], $data["trace_id"])
            || !is_string($data["notification_id"])
            || !is_int($data["attempt"])
            || $data["attempt"] < 1
            || !is_string($data["trace_id"])
        ) {
            throw new UnexpectedValueException("Invalid notification queue payload.");
        }

        $trafficType = NotificationTrafficType::Marketing;

        if (isset($data["traffic_type"])) {
            if (!is_string($data["traffic_type"])) {
                throw new UnexpectedValueException("Invalid notification queue payload.");
            }

            $trafficType = NotificationTrafficType::tryFrom($data["traffic_type"])
                ?? throw new UnexpectedValueException("Invalid notification queue payload.");
        }

        return new QueueMessage(
            $data["notification_id"],
            $data["attempt"],
            $data["trace_id"],
            $trafficType,
        );
    }
}
