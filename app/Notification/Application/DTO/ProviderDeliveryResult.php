<?php

declare(strict_types=1);

namespace App\Notification\Application\DTO;

use App\Notification\Domain\Enum\ProviderDeliveryStatus;

final readonly class ProviderDeliveryResult
{
    public function __construct(
        public ProviderDeliveryStatus $status,
        public ?string $errorMessage = null,
    ) {}
}
