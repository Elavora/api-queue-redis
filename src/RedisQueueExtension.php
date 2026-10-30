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
use LogicException;

final class RedisQueueExtension implements Extension
{
    private readonly RedisConfig $redisConfig;
    private readonly string $prefix;

    /**
     * @param array{host?: string, port?: int|string, timeout?: float|int|string, password?: string|null, database?: int|string|null, prefix?: string} $config
     */
    public function __construct(private readonly array $config)
    {
        $this->redisConfig = RedisConfig::fromArray($config);
        $this->prefix = (string) ($config['prefix'] ?? '');
    }

    /**
     * Registra a fila Redis usando a factory Redis compartilhada.
     */
    public function register(Application $application): void
    {
        RedisServiceRegistrar::register($application);

        $application->container()->bind(
            Queue::class,
            fn(Container $container): RedisQueue => new RedisQueue(
                redis: $this->connectionFactory($container)->connect($this->redisConfig),
                prefix: $this->prefix,
            ),
        );
    }

    /**
     * Obtém a factory Redis do container, garantindo o contrato esperado.
     */
    private function connectionFactory(Container $container): RedisConnectionFactory
    {
        $factory = $container->get(RedisConnectionFactory::class);

        if (!$factory instanceof RedisConnectionFactory) {
            throw new LogicException(sprintf(
                'O binding de %s deve resolver uma instancia dessa interface.',
                RedisConnectionFactory::class,
            ));
        }

        return $factory;
    }
}
