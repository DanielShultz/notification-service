<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence\Eloquent;

use App\Notification\Application\DTO\CreateNotificationBatchRequest;
use App\Notification\Application\Port\NotificationBatchRepository;
use App\Notification\Domain\Enum\NotificationStatus;
use Illuminate\Support\Facades\DB;

final readonly class EloquentNotificationBatchRepository implements NotificationBatchRepository
{
    /**
     * @param array<string, string> $notificationIds
     */
    public function createBatch(CreateNotificationBatchRequest $request, string $batchId, array $notificationIds): void
    {
        DB::transaction(function () use ($request, $batchId, $notificationIds): void {
            $now = now();

            DB::table("notification_batches")->insert([
                "id" => $batchId,
                "idempotency_key" => $request->idempotencyKey,
                "channel" => $request->channel->value,
                "traffic_type" => $request->trafficType->value,
                "message" => $request->message,
                "recipient_count" => count($request->recipientIds),
                "created_at" => $now,
                "updated_at" => $now,
            ]);

            $notifications = [];
            $history = [];

            foreach ($notificationIds as $recipientId => $notificationId) {
                $notifications[] = [
                    "id" => $notificationId,
                    "batch_id" => $batchId,
                    "recipient_id" => $recipientId,
                    "channel" => $request->channel->value,
                    "traffic_type" => $request->trafficType->value,
                    "status" => NotificationStatus::Queued->value,
                    "attempts" => 0,
                    "created_at" => $now,
                    "updated_at" => $now,
                ];

                $history[] = [
                    "notification_id" => $notificationId,
                    "status" => NotificationStatus::Queued->value,
                    "reason" => null,
                    "created_at" => $now,
                ];
            }

            DB::table("notifications")->insert($notifications);
            DB::table("notification_status_history")->insert($history);
        });
    }

    public function findBatchIdByIdempotencyKey(string $idempotencyKey): ?string
    {
        $id = DB::table("notification_batches")
            ->where("idempotency_key", $idempotencyKey)
            ->value("id");

        if ($id === null) {
            return null;
        }

        return (string)$id;
    }

    public function countNotifications(string $batchId): int
    {
        return DB::table("notifications")
            ->where("batch_id", $batchId)
            ->count();
    }
}
