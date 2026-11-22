<?php

declare(strict_types=1);

use Elavora\Api\Extension\QueueRedis\ReliableRedisQueue;
use Elavora\Api\Extension\Redis\NativeRedisClient;
use PHPUnit\Framework\TestCase;

/**
 * Integração da fila com confirmação contra Redis real (6.2 ou superior). Pulado quando ext-redis não está
 * carregada ou quando não há servidor Redis acessível em REDIS_HOST:REDIS_PORT.
 */
final class ReliableRedisQueueRedisTest extends TestCase
{
    private Redis $redis;

    private string $prefix;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis não está carregada; teste com Redis real pulado.');
        }

        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $this->redis = new Redis();
            $connected = @$this->redis->connect($host, $port, 1.0);
        } catch (Throwable) {
            $connected = false;
        }

        if (!$connected) {
            self::markTestSkipped(sprintf('Servidor Redis inacessível em %s:%d; teste com Redis real pulado.', $host, $port));
        }

        $this->prefix = 'test:reliable:' . bin2hex(random_bytes(4)) . ':';
    }

    protected function tearDown(): void
    {
        if (isset($this->redis, $this->prefix)) {
            $keys = $this->redis->keys($this->prefix . '*');
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
        }
    }

    private function queue(int $timeout = 300): ReliableRedisQueue
    {
        return new ReliableRedisQueue(new NativeRedisClient($this->redis), $this->prefix, $timeout);
    }

    public function testReserveAcknowledgeCycle(): void
    {
        $queue = $this->queue();
        $queue->push('jobs', ['n' => 1]);

        $job = $queue->reserve(['jobs']);

        self::assertSame(['n' => 1], $job?->payload());
        self::assertSame(1, $this->redis->lLen($this->prefix . 'jobs:processing'));
        self::assertNull($queue->reserve(['jobs']));

        $queue->acknowledge($job);

        self::assertSame(0, $this->redis->lLen($this->prefix . 'jobs:processing'));
        self::assertSame([], $this->redis->keys($this->prefix . 'jobs:lease:*'));
    }

    public function testJobWithoutAckReturnsToTheQueueAfterTheVisibilityTimeout(): void
    {
        $queue = $this->queue(timeout: 1);
        $queue->push('jobs', ['n' => 1]);
        $queue->push('jobs', ['n' => 2]);
        $queue->reserve(['jobs']);

        usleep(1_300_000);

        $again = $queue->reserve(['jobs']);

        self::assertSame(['n' => 1], $again?->payload());
        self::assertSame(['n' => 2], $queue->reserve(['jobs'])?->payload());
    }

    public function testExtendKeepsTheJobInProcessing(): void
    {
        $queue = $this->queue(timeout: 1);
        $queue->push('jobs', ['n' => 1]);
        $job = $queue->reserve(['jobs']);
        self::assertNotNull($job);

        $queue->extend($job, 5);
        usleep(1_300_000);

        self::assertSame(0, $queue->recoverExpired('jobs'));
        self::assertNull($queue->reserve(['jobs']));
    }

    public function testPriorityOrder(): void
    {
        $queue = $this->queue();
        $queue->push('tags', ['t' => 1]);
        $queue->push('conteudo', ['c' => 1]);

        self::assertSame(['c' => 1], $queue->reserve(['conteudo', 'tags'])?->payload());
        self::assertSame(['t' => 1], $queue->reserve(['conteudo', 'tags'])?->payload());
    }
}
