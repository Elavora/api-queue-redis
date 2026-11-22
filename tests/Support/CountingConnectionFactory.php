<?php

declare(strict_types=1);

use Elavora\Api\Extension\Redis\Contracts\RedisClient;
use Elavora\Api\Extension\Redis\Contracts\RedisConnectionFactory;
use Elavora\Api\Extension\Redis\RedisConfig;

/**
 * Factory falsa que conta as conexões e devolve sempre o mesmo cliente em memória.
 */
final class CountingConnectionFactory implements RedisConnectionFactory
{
    public int $connections = 0;

    public ?RedisConfig $lastConfig = null;

    public RedisClient $client;

    public function __construct(?RedisClient $client = null)
    {
        $this->client = $client ?? new InMemoryRedisClient();
    }

    public function connect(RedisConfig $config): RedisClient
    {
        $this->connections++;
        $this->lastConfig = $config;

        return $this->client;
    }
}
