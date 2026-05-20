<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Queue\RabbitMq;

final readonly class RabbitMqConfig
{
    /**
     * @param  array{high: string, default: string, retry: string, dead_letter: string}  $queues
     * @param  array{high: string, default: string, dead_letter: string}  $routingKeys
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public string $password,
        public string $vhost,
        public string $exchange,
        public string $retryExchange,
        public string $deadLetterExchange,
        public array $queues,
        public array $routingKeys,
    ) {}

    /**
     * @param array{
     *     host: string,
     *     port: int,
     *     user: string,
     *     password: string,
     *     vhost: string,
     *     exchange: string,
     *     retry_exchange: string,
     *     dead_letter_exchange: string,
     *     queues: array{high: string, default: string, retry: string, dead_letter: string},
     *     routing_keys: array{high: string, default: string, dead_letter: string}
     * } $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost'],
            $config['exchange'],
            $config['retry_exchange'],
            $config['dead_letter_exchange'],
            $config['queues'],
            $config['routing_keys'],
        );
    }
}
