<?php

declare(strict_types=1);

namespace App\Notification\Application\Service;

use App\Notification\Infrastructure\Queue\RabbitMq\RabbitMqNotificationConsumer;
use Psr\Log\LoggerInterface;

final readonly class ConsumeNotifications
{
    public function __construct(
        private RabbitMqNotificationConsumer $consumer,
        private LoggerInterface $logger,
    ) {
    }

    public function execute(bool $runOnce): int
    {
        $this->logger->info("Notification consumer started");

        if ($runOnce === true) {
            $this->consumer->consumeOnce();

            return 0;
        }

        while (true) {
            $processed = $this->consumer->consumeOnce();

            if ($processed === false) {
                sleep(1);
            }
        }

        return 0;
    }
}
