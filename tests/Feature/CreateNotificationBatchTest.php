<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notification\Infrastructure\Cache\Redis\RedisIdempotencyStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreateNotificationBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();
    }

    public function test_creates_notification_batch(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $response = $this
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/notification-batches', [
                'channel' => 'sms',
                'traffic_type' => 'transactional',
                'message' => 'Access code: 1234',
                'recipient_ids' => ['user-1', 'user-2'],
            ]);

        $response
            ->assertStatus(202)
            ->assertJson([
                'notification_count' => 2,
                'duplicate' => false,
            ]);

        $batchId = (string) $response->json('batch_id');

        $this->assertDatabaseHas('notification_batches', [
            'id' => $batchId,
            'idempotency_key' => $idempotencyKey,
            'channel' => 'sms',
            'traffic_type' => 'transactional',
            'recipient_count' => 2,
        ]);

        $notificationIds = DB::table('notifications')
            ->where('batch_id', $batchId)
            ->pluck('id');

        $this->assertSame(2, $notificationIds->count());
        $this->assertSame(
            2,
            DB::table('notification_status_history')
                ->whereIn('notification_id', $notificationIds)
                ->where('status', 'queued')
                ->count()
        );
    }

    public function test_returns_existing_batch_for_duplicate_idempotency_key(): void
    {
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'channel' => 'email',
            'traffic_type' => 'marketing',
            'message' => 'Monthly promo',
            'recipient_ids' => ['user-3', 'user-4', 'user-5'],
        ];

        $firstResponse = $this
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/notification-batches', $payload);

        $secondResponse = $this
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/notification-batches', $payload);

        $secondResponse
            ->assertStatus(200)
            ->assertJson([
                'batch_id' => $firstResponse->json('batch_id'),
                'notification_count' => 3,
                'duplicate' => true,
            ]);

        $this->assertSame(1, DB::table('notification_batches')->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_returns_existing_batch_when_redis_idempotency_cache_expired(): void
    {
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'channel' => 'email',
            'traffic_type' => 'marketing',
            'message' => 'Monthly promo',
            'recipient_ids' => ['user-3'],
        ];

        $firstResponse = $this
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/notification-batches', $payload);

        cache()->forget(RedisIdempotencyStore::KEY_PREFIX.$idempotencyKey);

        $secondResponse = $this
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/notification-batches', $payload);

        $secondResponse
            ->assertStatus(200)
            ->assertJson([
                'batch_id' => $firstResponse->json('batch_id'),
                'notification_count' => 1,
                'duplicate' => true,
            ]);

        $this->assertSame(1, DB::table('notification_batches')->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_requires_idempotency_key_header(): void
    {
        $response = $this->postJson('/api/v1/notification-batches', [
            'channel' => 'sms',
            'traffic_type' => 'transactional',
            'message' => 'Access code: 1234',
            'recipient_ids' => ['user-1'],
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Idempotency-Key header is required.',
            ]);
    }

    public function test_rejects_invalid_idempotency_key_header(): void
    {
        $response = $this
            ->withHeader('Idempotency-Key', 'invalid key')
            ->postJson('/api/v1/notification-batches', [
                'channel' => 'sms',
                'traffic_type' => 'transactional',
                'message' => 'Access code: 1234',
                'recipient_ids' => ['user-1'],
            ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Idempotency-Key header must be 255 characters or less and contain only letters, digits, dots, underscores, colons, or hyphens.',
            ]);
    }

    public function test_rejects_too_long_idempotency_key_header(): void
    {
        $response = $this
            ->withHeader('Idempotency-Key', str_repeat('a', 256))
            ->postJson('/api/v1/notification-batches', [
                'channel' => 'sms',
                'traffic_type' => 'transactional',
                'message' => 'Access code: 1234',
                'recipient_ids' => ['user-1'],
            ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Idempotency-Key header must be 255 characters or less and contain only letters, digits, dots, underscores, colons, or hyphens.',
            ]);
    }

    public function test_returns_conflict_when_idempotency_key_is_reserved(): void
    {
        cache()->add(RedisIdempotencyStore::KEY_PREFIX.'reserved-key', '__reserved__', 60);

        $response = $this
            ->withHeader('Idempotency-Key', 'reserved-key')
            ->postJson('/api/v1/notification-batches', [
                'channel' => 'sms',
                'traffic_type' => 'transactional',
                'message' => 'Access code: 1234',
                'recipient_ids' => ['user-1'],
            ]);

        $response
            ->assertConflict()
            ->assertJson([
                'message' => 'Request with this Idempotency-Key is already being processed.',
            ]);
    }

    public function test_rate_limits_batch_creation(): void
    {
        RateLimiter::for('notification-batches', static function (Request $request): Limit {
            return Limit::perMinute(2)->by('test-client');
        });

        try {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $this
                    ->withHeader('Idempotency-Key', 'rate-limit-'.$attempt)
                    ->postJson('/api/v1/notification-batches', [
                        'channel' => 'sms',
                        'traffic_type' => 'transactional',
                        'message' => 'Access code: 1234',
                        'recipient_ids' => ['user-'.$attempt],
                    ])
                    ->assertAccepted();
            }

            $this
                ->withHeader('Idempotency-Key', 'rate-limit-3')
                ->postJson('/api/v1/notification-batches', [
                    'channel' => 'sms',
                    'traffic_type' => 'transactional',
                    'message' => 'Access code: 1234',
                    'recipient_ids' => ['user-3'],
                ])
                ->assertStatus(429);
        } finally {
            RateLimiter::for('notification-batches', static function (Request $request): Limit {
                return Limit::perMinute(30)->by($request->ip() ?? 'unknown');
            });
        }
    }
}
