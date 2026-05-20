<?php

declare(strict_types=1);

namespace App\Notification\Presentation\Http;

use App\Notification\Domain\Enum\NotificationStatus;

final readonly class NotificationStatusPresenter
{
    public static function publicStatus(string $status): string
    {
        return match ($status) {
            NotificationStatus::Processing->value,
            NotificationStatus::RetryScheduled->value => NotificationStatus::Queued->value,
            default => $status,
        };
    }

    /**
     * @param  array<string, int>  $statusCounts
     * @return array<string, int>
     */
    public static function publicStatusCounts(array $statusCounts): array
    {
        $publicCounts = [];

        foreach ($statusCounts as $status => $count) {
            $publicStatus = self::publicStatus((string) $status);
            $publicCounts[$publicStatus] = ($publicCounts[$publicStatus] ?? 0) + $count;
        }

        return $publicCounts;
    }
}
