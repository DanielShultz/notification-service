<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Cache\Redis;

use App\Notification\Application\Port\IdempotencyStore;
use Illuminate\Contracts\Cache\Repository;

final readonly class RedisIdempotencyStore implements IdempotencyStore
{
    public const KEY_PREFIX = 'notification:idempotency:';

    private const RESERVED_VALUE = "__reserved__";

    public function __construct(
        private Repository $cache,
    ) {
    }

    public function reserve(string $key, int $ttlSeconds): bool
    {
        return $this->cache->add($this->key($key), self::RESERVED_VALUE, $ttlSeconds);
    }

    public function remember(string $key, string $resultId, int $ttlSeconds): void
    {
        $this->cache->put($this->key($key), $resultId, $ttlSeconds);
    }

    public function getResultId(string $key): ?string
    {
        $value = $this->cache->get($this->key($key));

        if ($value === null || $value === self::RESERVED_VALUE) {
            return null;
        }

        return (string)$value;
    }

    private function key(string $key): string
    {
        return "notification:idempotency:{$key}";
    }
}

