<?php

declare(strict_types=1);

namespace Elavora\Api\Extension\QueueRedis;

use Elavora\Api\Extension\Redis\Contracts\RedisConnectionFactory;
use Elavora\Api\Extension\Redis\Contracts\RedisListOperations;
use Elavora\Api\Extension\Redis\RedisConfig;
use Elavora\Api\Extension\Redis\RedisServiceRegistrar;
use Elavora\Api\Framework\Application;
use Elavora\Api\Framework\Container;
use Elavora\Api\Framework\Contracts\AcknowledgesJobs;
use Elavora\Api\Framework\Contracts\Extension;
use Elavora\Api\Framework\Contracts\Queue;
use InvalidArgumentException;
use LogicException;

final class RedisQueueExtension implements Extension
{
    private const int DEFAULT_VISIBILITY_TIMEOUT = 300;

    private readonly RedisConfig $redisConfig;
    private readonly string $prefix;
    private readonly int $visibilityTimeout;

    /**
     * @param array{host?: string, port?: int|string, timeout?: float|int|string, password?: string|null, database?: int|string|null, prefix?: string, visibility_timeout?: int|string|null} $config
     */
    public function __construct(private readonly array $config)
    {
        $this->redisConfig = RedisConfig::fromArray($config);
        $this->prefix = (string) ($config['prefix'] ?? '');
        $this->visibilityTimeout = self::visibilityTimeout($config['visibility_timeout'] ?? null);
    }

    /**
     * Registra a fila Redis usando a factory Redis compartilhada.
     *
     * Com suporte a AcknowledgesJobs (api-framework 6.4 ou superior) e a RedisListOperations (api-redis 3.1 ou
     * superior), registra a ReliableRedisQueue; senao, a RedisQueue (sem ack).
     */
    public function register(Application $application): void
    {
        RedisServiceRegistrar::register($application);

        $application->container()->bind(
            Queue::class,
            function (Container $container): Queue {
                $redis = $this->connectionFactory($container)->connect($this->redisConfig);

                if (interface_exists(AcknowledgesJobs::class) && $redis instanceof RedisListOperations) {
                    return new ReliableRedisQueue(
                        redis: $redis,
                        prefix: $this->prefix,
                        visibilityTimeout: $this->visibilityTimeout,
                    );
                }

                return new RedisQueue(redis: $redis, prefix: $this->prefix);
            },
        );
    }

    private static function visibilityTimeout(mixed $value): int
    {
        if ($value === null) {
            return self::DEFAULT_VISIBILITY_TIMEOUT;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        throw new InvalidArgumentException('visibility_timeout deve ser um inteiro maior que zero.');
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
