<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Queue\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

final class RabbitMqTopology
{
    private const EXCHANGE_TYPE_DIRECT = 'direct';

    private bool $declared = false;

    public function __construct(
        private readonly RabbitMqConfig $config,
    ) {}

    public function declareOnce(AMQPChannel $channel): void
    {
        if ($this->declared === true) {
            return;
        }

        $this->declare($channel);
        $this->declared = true;
    }

    public function declare(AMQPChannel $channel): void
    {
        $channel->exchange_declare($this->config->exchange, self::EXCHANGE_TYPE_DIRECT, false, true, false);
        $channel->exchange_declare($this->config->retryExchange, self::EXCHANGE_TYPE_DIRECT, false, true, false);
        $channel->exchange_declare($this->config->deadLetterExchange, self::EXCHANGE_TYPE_DIRECT, false, true, false);

        $deadLetterArguments = new AMQPTable([
            'x-dead-letter-exchange' => $this->config->deadLetterExchange,
            'x-dead-letter-routing-key' => $this->config->routingKeys['dead_letter'],
        ]);

        $channel->queue_declare($this->config->queues['high'], false, true, false, false, false, $deadLetterArguments);
        $channel->queue_declare(
            $this->config->queues['default'],
            false,
            true,
            false,
            false,
            false,
            $deadLetterArguments,
        );
        $channel->queue_declare(
            $this->config->queues['retry'],
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => $this->config->exchange,
            ]),
        );
        $channel->queue_declare($this->config->queues['dead_letter'], false, true, false, false);

        $channel->queue_bind(
            $this->config->queues['high'],
            $this->config->exchange,
            $this->config->routingKeys['high'],
        );
        $channel->queue_bind(
            $this->config->queues['default'],
            $this->config->exchange,
            $this->config->routingKeys['default'],
        );
        $channel->queue_bind(
            $this->config->queues['retry'],
            $this->config->retryExchange,
            $this->config->routingKeys['high'],
        );
        $channel->queue_bind(
            $this->config->queues['retry'],
            $this->config->retryExchange,
            $this->config->routingKeys['default'],
        );
        $channel->queue_bind(
            $this->config->queues['dead_letter'],
            $this->config->deadLetterExchange,
            $this->config->routingKeys['dead_letter'],
        );
    }
}
