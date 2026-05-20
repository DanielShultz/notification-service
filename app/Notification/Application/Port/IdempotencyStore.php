<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

interface IdempotencyStore
{
    public function reserve(string $key, int $ttlSeconds): bool;

    public function remember(string $key, string $resultId, int $ttlSeconds): void;

    public function getResultId(string $key): ?string;
}

