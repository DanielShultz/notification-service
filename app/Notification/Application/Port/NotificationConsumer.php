<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

interface NotificationConsumer
{
    public function consumeOnce(): bool;
}
