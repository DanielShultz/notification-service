<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Queue\RabbitMq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class RabbitMqConnectionFactory
{
    private ?AMQPStreamConnection $connection = null;

    public function __construct(
        private readonly RabbitMqConfig $config,
    ) {}

    public function create(): AMQPStreamConnection
    {
        if ($this->connection?->isConnected() === true) {
            return $this->connection;
        }

        $this->connection = new AMQPStreamConnection(
            $this->config->host,
            $this->config->port,
            $this->config->user,
            $this->config->password,
            $this->config->vhost,
        );

        return $this->connection;
    }

    public function isConnected(): bool
    {
        return $this->connection?->isConnected() === true;
    }

    public function close(): void
    {
        if ($this->connection?->isConnected() === true) {
            $this->connection->close();
        }

        $this->connection = null;
    }
}
