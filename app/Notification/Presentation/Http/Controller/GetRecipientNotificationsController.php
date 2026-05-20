<?php

declare(strict_types=1);

namespace App\Notification\Presentation\Http\Controller;

use App\Http\Controllers\Controller;
use App\Notification\Application\DTO\NotificationHistory;
use App\Notification\Application\DTO\NotificationHistoryEntry;
use App\Notification\Application\UseCase\GetRecipientNotifications;
use Illuminate\Http\JsonResponse;

final class GetRecipientNotificationsController extends Controller
{
    public function __invoke(string $recipientId, GetRecipientNotifications $useCase): JsonResponse
    {
        return response()->json([
            "recipient_id" => $recipientId,
            "notifications" => array_map(
                static fn (NotificationHistory $notification): array => [
                    "notification_id" => $notification->id,
                    "batch_id" => $notification->batchId,
                    "channel" => $notification->channel,
                    "traffic_type" => $notification->trafficType,
                    "status" => $notification->status,
                    "attempts" => $notification->attempts,
                    "last_error" => $notification->lastError,
                    "history" => array_map(
                        static fn (NotificationHistoryEntry $entry): array => [
                            "status" => $entry->status,
                            "reason" => $entry->reason,
                            "created_at" => $entry->createdAt,
                        ],
                        $notification->history,
                    ),
                ],
                $useCase->execute($recipientId),
            ),
        ]);
    }
}
