<?php

declare(strict_types=1);

use Elavora\Api\Extension\QueueRedis\RedisQueue;
use Elavora\Api\Extension\QueueRedis\RedisQueueExtension;
use Elavora\Api\Extension\Redis\Contracts\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisQueueFailureTest extends TestCase
{
    public function testAcceptsPositivePushResult(): void
    {
        $redis = new ControlledQueueRedisClient(1);
        $queue = new RedisQueue($redis, prefix: 'queue:');

        $queue->push('jobs', ['job' => 'ping']);

        self::assertSame('queue:jobs', $redis->lastQueue);
    }

    #[DataProvider('failedPushResultProvider')]
    public function testPropagatesFailedPushWithoutPayload(int|false $result): void
    {
        $redis = new ControlledQueueRedisClient($result);
        $queue = new RedisQueue($redis);
        $payload = ['secret' => 'sensitive-payload'];

        try {
            $queue->push('critical-jobs', $payload);
            self::fail('O enfileiramento deveria falhar.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                "Falha ao enfileirar mensagem na fila Redis 'critical-jobs'.",
                $exception->getMessage()
            );
            self::assertStringNotContainsString('sensitive-payload', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int|false}>
     */
    public static function failedPushResultProvider(): iterable
    {
        yield 'falha Redis' => [false];
        yield 'comprimento impossivel' => [0];
    }

    public function testRejectsInvalidPrefixConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('O prefixo da fila Redis deve ser uma string.');

        new RedisQueueExtension(['prefix' => 123]);
    }
}

final class ControlledQueueRedisClient implements RedisClient
{
    public ?string $lastQueue = null;

    public function __construct(private readonly int|false $pushResult)
    {
    }

    public function get(string $key): string|false
    {
        return false;
    }

    public function set(string $key, string $value): bool
    {
        return true;
    }

    public function setex(string $key, int $ttlSeconds, string $value): bool
    {
        return true;
    }

    public function del(string ...$keys): int|false
    {
        return 0;
    }

    public function rPush(string $key, string $value): int|false
    {
        $this->lastQueue = $key;

        return $this->pushResult;
    }

    public function lPop(string $key): string|false
    {
        return false;
    }
}
