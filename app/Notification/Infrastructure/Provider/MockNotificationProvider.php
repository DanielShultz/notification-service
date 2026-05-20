<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Provider;

use App\Notification\Application\DTO\NotificationForDelivery;
use App\Notification\Application\DTO\ProviderDeliveryResult;
use App\Notification\Application\Port\NotificationProvider;
use App\Notification\Domain\Enum\ProviderDeliveryStatus;

final readonly class MockNotificationProvider implements NotificationProvider
{
    private const TEMPORARY_FAILURE_MARKER = "[temporary-failure]";
    private const PERMANENT_FAILURE_MARKER = "[permanent-failure]";

    public function send(NotificationForDelivery $notification): ProviderDeliveryResult
    {
        if (str_contains($notification->message, self::PERMANENT_FAILURE_MARKER)) {
            return new ProviderDeliveryResult(
                ProviderDeliveryStatus::PermanentFailure,
                "Provider rejected recipient",
            );
        }

        if (str_contains($notification->message, self::TEMPORARY_FAILURE_MARKER)) {
            return new ProviderDeliveryResult(
                ProviderDeliveryStatus::TemporaryFailure,
                "Provider is temporarily unavailable",
            );
        }

        return new ProviderDeliveryResult(ProviderDeliveryStatus::Delivered);
    }
}

