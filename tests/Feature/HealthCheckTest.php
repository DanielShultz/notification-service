<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_service_status(): void
    {
        $response = $this->get('/api/v1/health');

        $response
            ->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'service' => 'notification-service',
                'dependencies' => [
                    'postgresql' => true,
                    'redis' => true,
                    'rabbitmq' => true,
                ],
            ]);
    }
}
