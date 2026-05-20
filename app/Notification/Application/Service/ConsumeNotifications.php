<?php

declare(strict_types=1);

namespace App\Notification\Application\Service;

use App\Notification\Application\Port\NotificationConsumer;
use Psr\Log\LoggerInterface;

final class ConsumeNotifications
{
    private bool $running = true;

    public function __construct(
        private readonly NotificationConsumer $consumer,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(bool $runOnce): int
    {
        $this->logger->info('Notification consumer started');

        if ($runOnce === true) {
            $this->consumer->consumeOnce();

            return 0;
        }

        $this->listenForShutdownSignals();

        while ($this->running === true) {
            $this->dispatchPendingSignals();

            $processed = $this->consumer->consumeOnce();

            if ($processed === false) {
                sleep(1);
            }
        }

        $this->logger->info('Notification consumer stopped');

        return 0;
    }

    private function listenForShutdownSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, function (): void {
            $this->running = false;
            $this->logger->info('Notification consumer received SIGTERM');
        });
        pcntl_signal(SIGINT, function (): void {
            $this->running = false;
            $this->logger->info('Notification consumer received SIGINT');
        });
    }

    private function dispatchPendingSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
