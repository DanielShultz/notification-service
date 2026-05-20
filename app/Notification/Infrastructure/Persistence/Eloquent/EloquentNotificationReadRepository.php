<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence\Eloquent;

use App\Notification\Application\DTO\NotificationBatchStatus;
use App\Notification\Application\DTO\NotificationHistory;
use App\Notification\Application\DTO\NotificationHistoryEntry;
use App\Notification\Application\DTO\NotificationSummary;
use App\Notification\Application\Port\NotificationReadRepository;
use Illuminate\Support\Facades\DB;

final readonly class EloquentNotificationReadRepository implements NotificationReadRepository
{
    public function findBatchStatus(string $batchId): ?NotificationBatchStatus
    {
        $batch = DB::table('notification_batches')
            ->where('id', $batchId)
            ->first();

        if ($batch === null) {
            return null;
        }

        $statusCounts = DB::table('notifications')
            ->where('batch_id', $batchId)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $notifications = DB::table('notifications')
            ->where('batch_id', $batchId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): NotificationSummary => new NotificationSummary(
                (string) $row->id,
                (string) $row->recipient_id,
                (string) $row->status,
                (int) $row->attempts,
                $row->last_error === null ? null : (string) $row->last_error,
                (string) $row->created_at,
                (string) $row->updated_at,
            ))
            ->values()
            ->all();

        return new NotificationBatchStatus(
            (string) $batch->id,
            (string) $batch->channel,
            (string) $batch->traffic_type,
            (int) $batch->recipient_count,
            $statusCounts,
            $notifications,
            (string) $batch->created_at,
            (string) $batch->updated_at,
        );
    }

    public function findNotificationHistory(string $notificationId): ?NotificationHistory
    {
        $notification = DB::table('notifications')
            ->where('id', $notificationId)
            ->first();

        if ($notification === null) {
            return null;
        }

        $history = DB::table('notification_status_history')
            ->where('notification_id', $notificationId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): NotificationHistoryEntry => new NotificationHistoryEntry(
                (string) $row->status,
                $row->reason === null ? null : (string) $row->reason,
                (string) $row->created_at,
            ))
            ->values()
            ->all();

        return new NotificationHistory(
            (string) $notification->id,
            (string) $notification->batch_id,
            (string) $notification->recipient_id,
            (string) $notification->channel,
            (string) $notification->traffic_type,
            (string) $notification->status,
            (int) $notification->attempts,
            $notification->last_error === null ? null : (string) $notification->last_error,
            $history,
        );
    }

    public function findRecipientNotifications(string $recipientId): array
    {
        $notifications = DB::table('notifications')
            ->where('recipient_id', $recipientId)
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();

        if ($notifications->isEmpty()) {
            return [];
        }

        $notificationIds = $notifications
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $historyByNotificationId = DB::table('notification_status_history')
            ->whereIn('notification_id', $notificationIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('notification_id');

        return $notifications
            ->map(static function (object $notification) use ($historyByNotificationId): NotificationHistory {
                $historyRows = $historyByNotificationId->get((string) $notification->id, collect());

                return new NotificationHistory(
                    (string) $notification->id,
                    (string) $notification->batch_id,
                    (string) $notification->recipient_id,
                    (string) $notification->channel,
                    (string) $notification->traffic_type,
                    (string) $notification->status,
                    (int) $notification->attempts,
                    $notification->last_error === null ? null : (string) $notification->last_error,
                    $historyRows
                        ->map(static fn (object $row): NotificationHistoryEntry => new NotificationHistoryEntry(
                            (string) $row->status,
                            $row->reason === null ? null : (string) $row->reason,
                            (string) $row->created_at,
                        ))
                        ->values()
                        ->all(),
                );
            })
            ->values()
            ->all();
    }
}
