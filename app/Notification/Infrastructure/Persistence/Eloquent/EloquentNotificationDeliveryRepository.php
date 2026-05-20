<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence\Eloquent;

use App\Notification\Application\DTO\NotificationForDelivery;
use App\Notification\Application\DTO\QueuedNotificationForPublishing;
use App\Notification\Application\Port\NotificationDeliveryRepository;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationStatus;
use App\Notification\Domain\Enum\NotificationTrafficType;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class EloquentNotificationDeliveryRepository implements NotificationDeliveryRepository
{
    public function claimForDelivery(string $notificationId): ?NotificationForDelivery
    {
        return DB::transaction(function () use ($notificationId): ?NotificationForDelivery {
            $row = $this->findDeliveryRow($notificationId);

            if ($row === null) {
                return null;
            }

            $status = NotificationStatus::from((string) $row->status);

            if (in_array($status, [
                NotificationStatus::Sent,
                NotificationStatus::Delivered,
                NotificationStatus::Dropped,
            ], true)) {
                return $this->mapDeliveryRow($row);
            }

            if (! in_array($status, [
                NotificationStatus::Queued,
                NotificationStatus::RetryScheduled,
            ], true)) {
                return null;
            }

            $now = now();
            $updated = DB::table('notifications')
                ->where('id', $notificationId)
                ->where('status', $status->value)
                ->update([
                    'status' => NotificationStatus::Processing->value,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return null;
            }

            self::insertHistory($notificationId, NotificationStatus::Processing, null, $now);

            $row->status = NotificationStatus::Processing->value;

            return $this->mapDeliveryRow($row);
        });
    }

    public function findQueuedForPublishing(int $limit, int $minAgeSeconds): array
    {
        return DB::table('notifications')
            ->where('status', NotificationStatus::Queued->value)
            ->where('created_at', '<=', now()->subSeconds($minAgeSeconds))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): QueuedNotificationForPublishing => new QueuedNotificationForPublishing(
                (string) $row->id,
                (string) $row->batch_id,
                NotificationTrafficType::from((string) $row->traffic_type),
                max(1, (int) $row->attempts + 1),
            ))
            ->values()
            ->all();
    }

    public function markSent(string $notificationId, int $attempts): void
    {
        $now = now();

        DB::transaction(static function () use ($notificationId, $attempts, $now): void {
            DB::table('notifications')
                ->where('id', $notificationId)
                ->update([
                    'status' => NotificationStatus::Sent->value,
                    'attempts' => $attempts,
                    'sent_at' => $now,
                    'updated_at' => $now,
                ]);

            self::insertHistory($notificationId, NotificationStatus::Sent, null, $now);
        });
    }

    public function markDelivered(string $notificationId): void
    {
        $now = now();

        DB::transaction(static function () use ($notificationId, $now): void {
            DB::table('notifications')
                ->where('id', $notificationId)
                ->update([
                    'status' => NotificationStatus::Delivered->value,
                    'delivered_at' => $now,
                    'updated_at' => $now,
                ]);

            self::insertHistory($notificationId, NotificationStatus::Delivered, null, $now);
        });
    }

    public function markSentAndDelivered(string $notificationId, int $attempts): void
    {
        $now = now();

        DB::transaction(static function () use ($notificationId, $attempts, $now): void {
            DB::table('notifications')
                ->where('id', $notificationId)
                ->update([
                    'status' => NotificationStatus::Delivered->value,
                    'attempts' => $attempts,
                    'sent_at' => $now,
                    'delivered_at' => $now,
                    'updated_at' => $now,
                ]);

            self::insertHistory($notificationId, NotificationStatus::Sent, null, $now);
            self::insertHistory($notificationId, NotificationStatus::Delivered, null, $now);
        });
    }

    public function markDropped(string $notificationId, string $reason): void
    {
        $now = now();

        DB::transaction(static function () use ($notificationId, $reason, $now): void {
            DB::table('notifications')
                ->where('id', $notificationId)
                ->update([
                    'status' => NotificationStatus::Dropped->value,
                    'dropped_at' => $now,
                    'last_error' => $reason,
                    'updated_at' => $now,
                ]);

            self::insertHistory($notificationId, NotificationStatus::Dropped, $reason, $now);
        });
    }

    public function markRetryScheduled(string $notificationId, int $attempts, string $reason): void
    {
        $now = now();

        DB::transaction(static function () use ($notificationId, $attempts, $reason, $now): void {
            DB::table('notifications')
                ->where('id', $notificationId)
                ->update([
                    'attempts' => $attempts,
                    'status' => NotificationStatus::RetryScheduled->value,
                    'last_error' => $reason,
                    'updated_at' => $now,
                ]);

            self::insertHistory($notificationId, NotificationStatus::RetryScheduled, $reason, $now);
        });
    }

    private static function insertHistory(
        string $notificationId,
        NotificationStatus $status,
        ?string $reason,
        CarbonInterface $createdAt,
    ): void {
        DB::table('notification_status_history')->insert([
            'notification_id' => $notificationId,
            'status' => $status->value,
            'reason' => $reason,
            'created_at' => $createdAt,
        ]);
    }

    private function findDeliveryRow(string $notificationId): ?object
    {
        return DB::table('notifications')
            ->join('notification_batches', 'notification_batches.id', '=', 'notifications.batch_id')
            ->where('notifications.id', $notificationId)
            ->select([
                'notifications.id',
                'notifications.batch_id',
                'notifications.recipient_id',
                'notifications.channel',
                'notifications.traffic_type',
                'notifications.status',
                'notifications.attempts',
                'notification_batches.message',
            ])
            ->first();
    }

    private function mapDeliveryRow(object $row): NotificationForDelivery
    {
        return new NotificationForDelivery(
            (string) $row->id,
            (string) $row->batch_id,
            (string) $row->recipient_id,
            NotificationChannel::from((string) $row->channel),
            NotificationTrafficType::from((string) $row->traffic_type),
            NotificationStatus::from((string) $row->status),
            (string) $row->message,
            (int) $row->attempts,
        );
    }
}
