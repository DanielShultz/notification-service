<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Queue\RabbitMq;

use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Application\Port\NotificationConsumer;
use App\Notification\Application\Port\NotificationDeliveryRepository;
use App\Notification\Application\Port\NotificationQueuePublisher;
use App\Notification\Application\UseCase\ProcessNotificationDelivery;
use JsonException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

final readonly class RabbitMqNotificationConsumer implements NotificationConsumer
{
    public function __construct(
        private RabbitMqConnectionFactory $connectionFactory,
        private RabbitMqTopology $topology,
        private RabbitMqConfig $config,
        private QueueMessageSerializer $serializer,
        private ProcessNotificationDelivery $processNotificationDelivery,
        private NotificationDeliveryRepository $deliveryRepository,
        private NotificationQueuePublisher $queuePublisher,
        private LoggerInterface $logger,
    ) {}

    public function consumeOnce(): bool
    {
        $connection = $this->connectionFactory->create();
        $channel = $connection->channel();

        try {
            $this->topology->declareOnce($channel);

            $message = $channel->basic_get($this->config->queues['high']);
            $queueName = $this->config->queues['high'];

            if ($message === null) {
                $message = $channel->basic_get($this->config->queues['default']);
                $queueName = $this->config->queues['default'];
            }

            if ($message === null) {
                return false;
            }

            $this->handleMessage($message, $queueName);

            return true;
        } finally {
            $channel->close();
        }
    }

    private function handleMessage(AMQPMessage $message, string $queueName): void
    {
        $queueMessage = null;

        try {
            $queueMessage = $this->serializer->deserialize($message->getBody());
            $this->processNotificationDelivery->execute($queueMessage);
            $message->ack();
        } catch (JsonException|UnexpectedValueException $exception) {
            $this->logger->error('Invalid notification message rejected', [
                'queue' => $queueName,
                'error' => $exception->getMessage(),
            ]);

            $message->reject(false);
        } catch (Throwable $exception) {
            $this->logger->error('Notification message processing failed', [
                'queue' => $queueName,
                'error' => $exception->getMessage(),
            ]);

            $this->retryOrDeadLetter($queueMessage, $exception->getMessage());
            $message->ack();
        }
    }

    private function retryOrDeadLetter(?QueueMessage $queueMessage, string $reason): void
    {
        if ($queueMessage === null) {
            return;
        }

        $maxAttempts = (int) config('notification.max_attempts');

        if ($queueMessage->attempt >= $maxAttempts) {
            $this->deliveryRepository->markDropped($queueMessage->notificationId, $reason);
            $this->queuePublisher->publishDeadLetter($queueMessage, $reason);

            return;
        }

        $this->deliveryRepository->markRetryScheduled($queueMessage->notificationId, $queueMessage->attempt, $reason);
        $this->queuePublisher->publishRetry(
            new QueueMessage(
                $queueMessage->notificationId,
                $queueMessage->attempt + 1,
                $queueMessage->batchId,
                $queueMessage->trafficType,
            ),
            (int) config('notification.retry_delay_seconds'),
        );
    }
}
