<?php

declare(strict_types=1);

use Elavora\Api\Extension\QueueRedis\RedisQueueExtension;
use Elavora\Api\Framework\Application;
use Elavora\Api\Framework\Contracts\Queue;
use PHPUnit\Framework\TestCase;

/**
 * Integração com Redis real. Pulado quando ext-redis não está carregada
 * ou quando não há servidor Redis acessível em REDIS_HOST:REDIS_PORT.
 */
final class RedisQueueTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis não está carregada; teste com Redis real pulado.');
        }

        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $probe = new \Redis();
            $connected = @$probe->connect($host, $port, 1.0);
            if ($connected) {
                $probe->close();
            }
        } catch (\Throwable) {
            $connected = false;
        }

        if (!$connected) {
            self::markTestSkipped(sprintf('Servidor Redis inacessível em %s:%d; teste com Redis real pulado.', $host, $port));
        }
    }

    public function testPushesAndPopsPayload(): void
    {
        $application = Application::create()->extend(new RedisQueueExtension([
            'host' => getenv('REDIS_HOST') ?: 'redis',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'prefix' => 'test:queue:',
        ]));
        $queue = $application->container()->get(Queue::class);

        $queue->push('jobs', ['job' => 'ping']);

        self::assertSame(['job' => 'ping'], $queue->pop('jobs'));
        self::assertNull($queue->pop('jobs'));
    }
}
