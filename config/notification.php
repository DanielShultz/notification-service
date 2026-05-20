<?php

declare(strict_types=1);

return [
    'max_attempts' => (int) env('NOTIFICATION_MAX_ATTEMPTS', 5),
    'retry_delay_seconds' => (int) env('NOTIFICATION_RETRY_DELAY_SECONDS', 30),
    'recovery_min_age_seconds' => (int) env('NOTIFICATION_RECOVERY_MIN_AGE_SECONDS', 300),
    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'notification_service'),
        'password' => env('RABBITMQ_PASSWORD', 'notification_service'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'exchange' => 'notifications.delivery',
        'retry_exchange' => 'notifications.retry',
        'dead_letter_exchange' => 'notifications.dead_letter',
        'routing_keys' => [
            'high' => 'notifications.high',
            'default' => 'notifications.default',
            'dead_letter' => 'notifications.dlq',
        ],
        'queues' => [
            'high' => 'notifications.high',
            'default' => 'notifications.default',
            'retry' => 'notifications.retry',
            'dead_letter' => 'notifications.dlq',
        ],
    ],
];
