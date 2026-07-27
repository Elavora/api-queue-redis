<?php

declare(strict_types=1);

namespace Elavora\Api\Extension\QueueRedis;

use Elavora\Api\Extension\Redis\Contracts\RedisConnectionFactory;
use Elavora\Api\Extension\Redis\RedisConfig;
use Elavora\Api\Extension\Redis\RedisServiceRegistrar;
use Elavora\Api\Framework\Application;
use Elavora\Api\Framework\Container;
use Elavora\Api\Framework\Contracts\Extension;
use Elavora\Api\Framework\Contracts\Queue;
use InvalidArgumentException;
use RuntimeException;

final class RedisQueueExtension implements Extension
{
    private readonly RedisConfig $redisConfig;
    private readonly string $prefix;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
        $this->redisConfig = RedisConfig::fromArray($config);
        $prefix = $config['prefix'] ?? '';
        if (!is_string($prefix)) {
            throw new InvalidArgumentException('O prefixo da fila Redis deve ser uma string.');
        }

        $this->prefix = $prefix;
    }

    /**
     * Registra a fila Redis usando a factory Redis compartilhada.
     */
    public function register(Application $application): void
    {
        RedisServiceRegistrar::register($application);

        $application->container()->bind(
            Queue::class,
            fn (Container $container): RedisQueue => $this->createQueue($container)
        );
    }

    private function createQueue(Container $container): RedisQueue
    {
        $factory = $container->get(RedisConnectionFactory::class);
        if (!$factory instanceof RedisConnectionFactory) {
            throw new RuntimeException('O servico RedisConnectionFactory possui tipo invalido.');
        }

        return new RedisQueue(
            redis: $factory->connect($this->redisConfig),
            prefix: $this->prefix
        );
    }
}
