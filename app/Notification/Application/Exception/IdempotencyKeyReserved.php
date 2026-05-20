<?php

declare(strict_types=1);

namespace App\Notification\Application\Exception;

use RuntimeException;

final class IdempotencyKeyReserved extends RuntimeException
{
    public function __construct()
    {
        parent::__construct("Idempotency key is already being processed.");
    }
}
