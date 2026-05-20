<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Queue\RabbitMq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final readonly class RabbitMqConnectionFactory
{
    public function __construct(
        private RabbitMqConfig $config,
    ) {
    }

    public function create(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            $this->config->host,
            $this->config->port,
            $this->config->user,
            $this->config->password,
            $this->config->vhost,
        );
    }
}

