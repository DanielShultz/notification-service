<?php

declare(strict_types=1);

namespace App\Notification\Presentation\Http\Controller;

use App\Http\Controllers\Controller;
use App\Notification\Application\DTO\NotificationSummary;
use App\Notification\Application\UseCase\GetNotificationBatchStatus;
use Illuminate\Http\JsonResponse;

final class GetNotificationBatchStatusController extends Controller
{
    public function __invoke(string $batchId, GetNotificationBatchStatus $useCase): JsonResponse
    {
        $batch = $useCase->execute($batchId);

        if ($batch === null) {
            return response()->json([
                "message" => "Notification batch not found.",
            ], 404);
        }

        return response()->json([
            "batch_id" => $batch->id,
            "channel" => $batch->channel,
            "traffic_type" => $batch->trafficType,
            "recipient_count" => $batch->recipientCount,
            "status_counts" => $batch->statusCounts,
            "notifications" => array_map(
                static fn (NotificationSummary $notification): array => [
                    "notification_id" => $notification->id,
                    "recipient_id" => $notification->recipientId,
                    "status" => $notification->status,
                    "attempts" => $notification->attempts,
                    "last_error" => $notification->lastError,
                    "created_at" => $notification->createdAt,
                    "updated_at" => $notification->updatedAt,
                ],
                $batch->notifications,
            ),
            "created_at" => $batch->createdAt,
            "updated_at" => $batch->updatedAt,
        ]);
    }
}
