<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\DTO\NotificationForDelivery;
use App\Notification\Application\DTO\ProviderDeliveryResult;

interface NotificationProvider
{
    public function send(NotificationForDelivery $notification): ProviderDeliveryResult;
}

