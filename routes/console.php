<?php

declare(strict_types=1);

use App\Notification\Application\Service\ConsumeNotifications;
use App\Notification\Application\UseCase\RecoverQueuedNotifications;
use Illuminate\Support\Facades\Artisan;

Artisan::command('notifications:consume {--once}', function (ConsumeNotifications $consumer): int {
    return $consumer->execute((bool) $this->option('once'));
})->purpose('Consume notification delivery messages from RabbitMQ');

Artisan::command(
    'notifications:recover-queued {--limit=100}',
    function (RecoverQueuedNotifications $recoverQueuedNotifications): int {
        $publishedCount = $recoverQueuedNotifications->execute((int) $this->option('limit'));

        $this->info("Published {$publishedCount} queued notifications.");

        return 0;
    },
)->purpose('Re-publish queued notifications that were persisted but not delivered to RabbitMQ');
