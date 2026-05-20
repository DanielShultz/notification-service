<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Domain\Enum\NotificationTrafficType;

interface NotificationQueuePublisher
{
    public function publish(QueueMessage $message, NotificationTrafficType $trafficType): void;

    public function publishRetry(QueueMessage $message, int $delaySeconds): void;

    public function publishDeadLetter(QueueMessage $message, string $reason): void;
}

