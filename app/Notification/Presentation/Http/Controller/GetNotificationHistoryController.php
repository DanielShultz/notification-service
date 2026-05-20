<?php

declare(strict_types=1);

namespace App\Notification\Presentation\Http\Controller;

use App\Http\Controllers\Controller;
use App\Notification\Application\DTO\NotificationHistoryEntry;
use App\Notification\Application\UseCase\GetNotificationHistory;
use Illuminate\Http\JsonResponse;

final class GetNotificationHistoryController extends Controller
{
    public function __invoke(string $notificationId, GetNotificationHistory $useCase): JsonResponse
    {
        $notification = $useCase->execute($notificationId);

        if ($notification === null) {
            return response()->json([
                "message" => "Notification not found.",
            ], 404);
        }

        return response()->json([
            "notification_id" => $notification->id,
            "batch_id" => $notification->batchId,
            "recipient_id" => $notification->recipientId,
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
        ]);
    }
}
