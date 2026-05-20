<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

enum ProviderDeliveryStatus: string
{
    case Delivered = 'delivered';
    case TemporaryFailure = 'temporary_failure';
    case PermanentFailure = 'permanent_failure';
}
