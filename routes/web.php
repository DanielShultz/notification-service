<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', static function (): array {
    return [
        'service' => 'notification-service',
        'documentation' => '/openapi.yaml',
    ];
});
