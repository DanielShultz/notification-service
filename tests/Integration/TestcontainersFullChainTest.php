<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Notification\Application\DTO\CreateNotificationBatchRequest;
use App\Notification\Application\DTO\QueueMessage;
use App\Notification\Application\Port\IdempotencyStore;
use App\Notification\Application\Port\NotificationQueuePublisher;
use App\Notification\Application\UseCase\CreateNotificationBatch;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationTrafficType;
use App\Notification\Infrastructure\Persistence\Eloquent\EloquentNotificationBatchRepository;
use App\Notification\Infrastructure\Queue\RabbitMq\RabbitMqConnectionFactory;
use App\Notification\Infrastructure\Queue\RabbitMq\RabbitMqTopology;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Log\NullLogger;
use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedGenericContainer;
use Testcontainers\Modules\PostgresContainer;
use Testcontainers\Modules\RedisContainer;
use Testcontainers\Wait\WaitForLog;
use Tests\TestCase;

final class TestcontainersFullChainTest extends TestCase
{
    private static ?StartedGenericContainer $postgres = null;

    private static ?StartedGenericContainer $redis = null;

    private static ?StartedGenericContainer $rabbitmq = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$postgres = (new PostgresContainer('15-alpine', 'notification_service', 'notification_service', 'notification_service'))
            ->start();
        self::$redis = (new RedisContainer('7-alpine'))
            ->start();
        self::$rabbitmq = (new GenericContainer('rabbitmq:3.13-management-alpine'))
            ->withEnvironment([
                'RABBITMQ_DEFAULT_USER' => 'notification_service',
                'RABBITMQ_DEFAULT_PASS' => 'notification_service',
            ])
            ->withExposedPorts(5672, 15672)
            ->withWait(new WaitForLog('Server startup complete', false, 30000))
            ->start();

        self::setEnv('DB_CONNECTION', 'pgsql');
        self::setEnv('DB_HOST', self::$postgres->getHost());
        self::setEnv('DB_PORT', (string) self::$postgres->getMappedPort(5432));
        self::setEnv('DB_DATABASE', 'notification_service');
        self::setEnv('DB_USERNAME', 'notification_service');
        self::setEnv('DB_PASSWORD', 'notification_service');
        self::setEnv('CACHE_STORE', 'redis');
        self::setEnv('REDIS_HOST', self::$redis->getHost());
        self::setEnv('REDIS_PORT', (string) self::$redis->getMappedPort(6379));
        self::setEnv('RABBITMQ_HOST', self::$rabbitmq->getHost());
        self::setEnv('RABBITMQ_PORT', (string) self::$rabbitmq->getMappedPort(5672));
        self::setEnv('RABBITMQ_USER', 'notification_service');
        self::setEnv('RABBITMQ_PASSWORD', 'notification_service');
        self::setEnv('NOTIFICATION_RETRY_DELAY_SECONDS', '1');
    }

    public static function tearDownAfterClass(): void
    {
        self::$rabbitmq?->stop();
        self::$redis?->stop();
        self::$postgres?->stop();

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'redis',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => self::$postgres?->getHost(),
            'database.connections.pgsql.port' => self::$postgres?->getMappedPort(5432),
            'database.connections.pgsql.database' => 'notification_service',
            'database.connections.pgsql.username' => 'notification_service',
            'database.connections.pgsql.password' => 'notification_service',
            'database.redis.default.host' => self::$redis?->getHost(),
            'database.redis.default.port' => self::$redis?->getMappedPort(6379),
            'notification.rabbitmq.host' => self::$rabbitmq?->getHost(),
            'notification.rabbitmq.port' => self::$rabbitmq?->getMappedPort(5672),
            'notification.rabbitmq.user' => 'notification_service',
            'notification.rabbitmq.password' => 'notification_service',
            'notification.retry_delay_seconds' => 1,
        ]);

        DB::purge('pgsql');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->purgeRabbitQueues();
    }

    public function test_processes_real_queue_messages_and_persists_delivery_history(): void
    {
        $successResponse = $this
            ->withHeader('Idempotency-Key', 'integration-success')
            ->postJson('/api/v1/notification-batches', [
                'channel' => 'sms',
                'traffic_type' => 'transactional',
                'message' => 'Integration success',
                'recipient_ids' => ['recipient-success'],
            ]);

        $successResponse->assertAccepted();
        $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => 'recipient-success',
            'status' => 'delivered',
        ]);
        $this->assertSame(
            ['queued', 'processing', 'sent', 'delivered'],
            DB::table('notification_status_history')
                ->join('notifications', 'notifications.id', '=', 'notification_status_history.notification_id')
                ->where('notifications.recipient_id', 'recipient-success')
                ->orderBy('notification_status_history.id')
                ->pluck('notification_status_history.status')
                ->all(),
        );
        $this->getJson('/api/v1/recipients/recipient-success/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.status', 'delivered');

        $failedResponse = $this
            ->withHeader('Idempotency-Key', 'integration-permanent-failure')
            ->postJson('/api/v1/notification-batches', [
                'channel' => 'sms',
                'traffic_type' => 'transactional',
                'message' => '[permanent-failure] Integration DLQ',
                'recipient_ids' => ['recipient-dlq'],
            ]);

        $failedResponse->assertAccepted();
        $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => 'recipient-dlq',
            'status' => 'dropped',
        ]);
        $this->assertGreaterThanOrEqual(1, $this->queueMessageCount('notifications.dlq'));
    }

    public function test_returns_existing_batch_when_postgresql_unique_constraint_wins_race(): void
    {
        $useCase = new CreateNotificationBatch(
            new EloquentNotificationBatchRepository,
            new IntegrationFakeQueuePublisher,
            new IntegrationAlwaysEmptyIdempotencyStore,
            new NullLogger,
        );
        $request = new CreateNotificationBatchRequest(
            NotificationChannel::Sms,
            NotificationTrafficType::Transactional,
            'Race condition fallback',
            ['recipient-race'],
            'integration-db-race',
        );

        $firstResponse = $useCase->execute($request);
        $secondResponse = $useCase->execute($request);

        $this->assertFalse($firstResponse->duplicate);
        $this->assertTrue($secondResponse->duplicate);
        $this->assertSame($firstResponse->batchId, $secondResponse->batchId);
        $this->assertSame(1, DB::table('notification_batches')->where('idempotency_key', 'integration-db-race')->count());
    }

    public function test_retries_temporary_failures_and_moves_to_dlq_after_max_attempts(): void
    {
        config(['notification.max_attempts' => 2]);

        $response = $this
            ->withHeader('Idempotency-Key', 'integration-temporary-failure')
            ->postJson('/api/v1/notification-batches', [
                'channel' => 'sms',
                'traffic_type' => 'transactional',
                'message' => '[temporary-failure] Integration retry',
                'recipient_ids' => ['recipient-temporary-failure'],
            ]);

        $response->assertAccepted();
        $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => 'recipient-temporary-failure',
            'status' => 'retry_scheduled',
            'attempts' => 1,
        ]);

        sleep(2);

        $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => 'recipient-temporary-failure',
            'status' => 'dropped',
            'attempts' => 1,
        ]);
        $this->assertSame(
            ['queued', 'processing', 'retry_scheduled', 'processing', 'dropped'],
            DB::table('notification_status_history')
                ->join('notifications', 'notifications.id', '=', 'notification_status_history.notification_id')
                ->where('notifications.recipient_id', 'recipient-temporary-failure')
                ->orderBy('notification_status_history.id')
                ->pluck('notification_status_history.status')
                ->all(),
        );
        $this->assertSame(1, $this->queueMessageCount('notifications.dlq'));
    }

    private static function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function queueMessageCount(string $queue): int
    {
        $connection = new AMQPStreamConnection(
            (string) config('notification.rabbitmq.host'),
            (int) config('notification.rabbitmq.port'),
            (string) config('notification.rabbitmq.user'),
            (string) config('notification.rabbitmq.password'),
            '/',
        );
        $channel = $connection->channel();

        try {
            [, $messageCount] = $channel->queue_declare($queue, true, true, false, false);

            return (int) $messageCount;
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    private function purgeRabbitQueues(): void
    {
        $connection = app(RabbitMqConnectionFactory::class)->create();
        $channel = $connection->channel();

        try {
            app(RabbitMqTopology::class)->declare($channel);

            foreach (config('notification.rabbitmq.queues') as $queue) {
                $channel->queue_purge((string) $queue);
            }
        } finally {
            $channel->close();
        }
    }
}

final readonly class IntegrationAlwaysEmptyIdempotencyStore implements IdempotencyStore
{
    public function reserve(string $key, int $ttlSeconds): bool
    {
        return true;
    }

    public function remember(string $key, string $resultId, int $ttlSeconds): void {}

    public function getResultId(string $key): ?string
    {
        return null;
    }
}

final class IntegrationFakeQueuePublisher implements NotificationQueuePublisher
{
    public function publish(QueueMessage $message, NotificationTrafficType $trafficType): void {}

    public function publishRetry(QueueMessage $message, int $delaySeconds): void {}

    public function publishDeadLetter(QueueMessage $message, string $reason): void {}
}
