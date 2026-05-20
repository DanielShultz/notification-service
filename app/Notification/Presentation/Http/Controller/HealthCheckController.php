<?php

declare(strict_types=1);

namespace App\Notification\Presentation\Http\Controller;

use App\Http\Controllers\Controller;
use App\Notification\Infrastructure\Queue\RabbitMq\RabbitMqConnectionFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthCheckController extends Controller
{
    public function __invoke(RabbitMqConnectionFactory $rabbitMq): JsonResponse
    {
        $dependencies = [
            'postgresql' => $this->checkPostgreSql(),
            'redis' => $this->checkRedis(),
            'rabbitmq' => $this->checkRabbitMq($rabbitMq),
        ];

        $isHealthy = ! in_array(false, $dependencies, true);

        return response()->json([
            'status' => $isHealthy ? 'ok' : 'unavailable',
            'service' => 'notification-service',
            'dependencies' => $dependencies,
        ], $isHealthy ? 200 : 503);
    }

    private function checkPostgreSql(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkRabbitMq(RabbitMqConnectionFactory $rabbitMq): bool
    {
        try {
            $rabbitMq->create();

            return $rabbitMq->isConnected();
        } catch (Throwable) {
            return false;
        }
    }
}
