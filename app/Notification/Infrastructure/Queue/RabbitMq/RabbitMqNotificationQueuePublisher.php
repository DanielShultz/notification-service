<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Queue\RabbitMq;

use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Application\Port\NotificationQueuePublisher;
use App\Notification\Domain\Enum\NotificationTrafficType;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

final readonly class RabbitMqNotificationQueuePublisher implements NotificationQueuePublisher
{
    private const DELIVERY_MODE_PERSISTENT = 2;

    public function __construct(
        private RabbitMqConnectionFactory $connectionFactory,
        private RabbitMqTopology $topology,
        private RabbitMqConfig $config,
        private QueueMessageSerializer $serializer,
        private LoggerInterface $logger,
    ) {}

    public function publish(QueueMessage $message, NotificationTrafficType $trafficType): void
    {
        $routingKey = $trafficType === NotificationTrafficType::Transactional
            ? $this->config->routingKeys['high']
            : $this->config->routingKeys['default'];

        $this->publishToExchange($this->config->exchange, $routingKey, $message);
        $this->logger->info('Notification published to RabbitMQ', [
            'notification_id' => $message->notificationId,
            'attempt' => $message->attempt,
            'batch_id' => $message->batchId,
            'routing_key' => $routingKey,
        ]);
    }

    public function publishRetry(QueueMessage $message, int $delaySeconds): void
    {
        $routingKey = $message->trafficType === NotificationTrafficType::Transactional
            ? $this->config->routingKeys['high']
            : $this->config->routingKeys['default'];

        $this->publishToExchange(
            $this->config->retryExchange,
            $routingKey,
            $message,
            $delaySeconds,
        );
    }

    public function publishDeadLetter(QueueMessage $message, string $reason): void
    {
        $this->publishToExchange($this->config->deadLetterExchange, $this->config->routingKeys['dead_letter'], $message);
        $this->logger->warning('Notification published to RabbitMQ dead letter queue', [
            'notification_id' => $message->notificationId,
            'attempt' => $message->attempt,
            'batch_id' => $message->batchId,
            'reason' => $reason,
        ]);
    }

    private function publishToExchange(
        string $exchange,
        string $routingKey,
        QueueMessage $message,
        ?int $delaySeconds = null,
    ): void {
        $connection = $this->connectionFactory->create();
        $channel = $connection->channel();

        try {
            $this->topology->declareOnce($channel);

            $properties = [
                'content_type' => 'application/json',
                'delivery_mode' => self::DELIVERY_MODE_PERSISTENT,
            ];

            if ($delaySeconds !== null) {
                $properties['expiration'] = (string) ($delaySeconds * 1000);
            }

            $channel->basic_publish(
                new AMQPMessage($this->serializer->serialize($message), $properties),
                $exchange,
                $routingKey,
            );
        } finally {
            $channel->close();
        }
    }
}
