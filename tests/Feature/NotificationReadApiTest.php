<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_batch_status(): void
    {
        $batchId = $this->createBatch();

        $response = $this->getJson("/api/v1/notification-batches/{$batchId}");

        $response
            ->assertOk()
            ->assertJson([
                'batch_id' => $batchId,
                'channel' => 'sms',
                'traffic_type' => 'transactional',
                'recipient_count' => 2,
                'status_counts' => [
                    'queued' => 2,
                ],
            ])
            ->assertJsonCount(2, 'notifications');
    }

    public function test_maps_internal_statuses_to_public_queued_status(): void
    {
        $batchId = $this->createBatch();
        $notificationId = (string) DB::table('notifications')
            ->where('batch_id', $batchId)
            ->orderBy('recipient_id')
            ->value('id');

        DB::table('notifications')
            ->where('id', $notificationId)
            ->update(['status' => 'retry_scheduled']);
        DB::table('notification_status_history')->insert([
            'notification_id' => $notificationId,
            'status' => 'processing',
            'reason' => null,
            'created_at' => now(),
        ]);
        DB::table('notification_status_history')->insert([
            'notification_id' => $notificationId,
            'status' => 'retry_scheduled',
            'reason' => 'temporary provider failure',
            'created_at' => now(),
        ]);

        $this->getJson("/api/v1/notification-batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('status_counts.queued', 2)
            ->assertJsonPath('notifications.0.status', 'queued');

        $this->getJson("/api/v1/notifications/{$notificationId}/history")
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('history.1.status', 'queued')
            ->assertJsonPath('history.2.status', 'queued');
    }

    public function test_returns_notification_history(): void
    {
        $batchId = $this->createBatch();
        $notificationId = (string) DB::table('notifications')
            ->where('batch_id', $batchId)
            ->orderBy('recipient_id')
            ->value('id');

        $response = $this->getJson("/api/v1/notifications/{$notificationId}/history");

        $response
            ->assertOk()
            ->assertJson([
                'notification_id' => $notificationId,
                'batch_id' => $batchId,
                'recipient_id' => 'user-1',
                'status' => 'queued',
                'attempts' => 0,
                'history' => [
                    [
                        'status' => 'queued',
                        'reason' => null,
                    ],
                ],
            ]);
    }

    public function test_returns_recipient_notifications(): void
    {
        $batchId = $this->createBatch();

        $response = $this->getJson('/api/v1/recipients/user-1/notifications');

        $response
            ->assertOk()
            ->assertJson([
                'recipient_id' => 'user-1',
                'notifications' => [
                    [
                        'batch_id' => $batchId,
                        'channel' => 'sms',
                        'traffic_type' => 'transactional',
                        'status' => 'queued',
                        'attempts' => 0,
                        'history' => [
                            [
                                'status' => 'queued',
                                'reason' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertJsonCount(1, 'notifications');
    }

    public function test_returns_empty_list_for_unknown_recipient(): void
    {
        $response = $this->getJson('/api/v1/recipients/unknown-recipient/notifications');

        $response
            ->assertOk()
            ->assertJson([
                'recipient_id' => 'unknown-recipient',
                'notifications' => [],
            ]);
    }

    public function test_returns_not_found_for_unknown_batch(): void
    {
        $response = $this->getJson('/api/v1/notification-batches/'.Str::uuid());

        $response
            ->assertNotFound()
            ->assertJson([
                'message' => 'Notification batch not found.',
            ]);
    }

    public function test_returns_not_found_for_unknown_notification(): void
    {
        $response = $this->getJson('/api/v1/notifications/'.Str::uuid().'/history');

        $response
            ->assertNotFound()
            ->assertJson([
                'message' => 'Notification not found.',
            ]);
    }

    private function createBatch(): string
    {
        $response = $this
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/notification-batches', [
                'channel' => 'sms',
                'traffic_type' => 'transactional',
                'message' => 'Access code: 1234',
                'recipient_ids' => ['user-1', 'user-2'],
            ]);

        $response->assertAccepted();

        return (string) $response->json('batch_id');
    }
}
