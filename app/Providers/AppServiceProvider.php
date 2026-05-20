<?php

declare(strict_types=1);

namespace App\Providers;

use App\Notification\Application\Port\IdempotencyStore;
use App\Notification\Application\Port\NotificationBatchRepository;
use App\Notification\Application\Port\NotificationConsumer;
use App\Notification\Application\Port\NotificationDeliveryRepository;
use App\Notification\Application\Port\NotificationProvider;
use App\Notification\Application\Port\NotificationQueuePublisher;
use App\Notification\Application\Port\NotificationReadRepository;
use App\Notification\Infrastructure\Cache\Redis\RedisIdempotencyStore;
use App\Notification\Infrastructure\Persistence\Eloquent\EloquentNotificationBatchRepository;
use App\Notification\Infrastructure\Persistence\Eloquent\EloquentNotificationDeliveryRepository;
use App\Notification\Infrastructure\Persistence\Eloquent\EloquentNotificationReadRepository;
use App\Notification\Infrastructure\Provider\MockNotificationProvider;
use App\Notification\Infrastructure\Queue\RabbitMq\RabbitMqConfig;
use App\Notification\Infrastructure\Queue\RabbitMq\RabbitMqConnectionFactory;
use App\Notification\Infrastructure\Queue\RabbitMq\RabbitMqNotificationConsumer as RabbitMqConsumer;
use App\Notification\Infrastructure\Queue\RabbitMq\RabbitMqNotificationQueuePublisher;
use App\Notification\Infrastructure\Queue\RabbitMq\RabbitMqTopology;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RabbitMqConfig::class, static fn (): RabbitMqConfig => RabbitMqConfig::fromArray(
            config('notification.rabbitmq'),
        ));
        $this->app->singleton(RabbitMqConnectionFactory::class);
        $this->app->singleton(RabbitMqTopology::class);

        $this->app->bind(NotificationBatchRepository::class, EloquentNotificationBatchRepository::class);
        $this->app->bind(NotificationDeliveryRepository::class, EloquentNotificationDeliveryRepository::class);
        $this->app->bind(NotificationReadRepository::class, EloquentNotificationReadRepository::class);
        $this->app->bind(NotificationProvider::class, MockNotificationProvider::class);
        $this->app->bind(NotificationQueuePublisher::class, RabbitMqNotificationQueuePublisher::class);
        $this->app->bind(NotificationConsumer::class, RabbitMqConsumer::class);
        $this->app->bind(IdempotencyStore::class, RedisIdempotencyStore::class);
    }

    public function boot(): void
    {
        RateLimiter::for('notification-batches', static function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip() ?? 'unknown');
        });
    }
}
