<?php

declare(strict_types=1);

use Elavora\Api\Extension\QueueRedis\RedisQueue;
use Elavora\Api\Framework\Contracts\Queue;
use PHPUnit\Framework\TestCase;

final class RedisQueueUnitTest extends TestCase
{
    public function testImplementsFrameworkQueueContract(): void
    {
        self::assertInstanceOf(Queue::class, new RedisQueue(new InMemoryRedisClient()));
    }

    public function testIsFinal(): void
    {
        self::assertTrue((new ReflectionClass(RedisQueue::class))->isFinal());
    }

    public function testPushCallsRPushOnceWithPrefixedKeyAndSerializedPayload(): void
    {
        $client = new InMemoryRedisClient();
        $queue = new RedisQueue(redis: $client, prefix: 'q:');

        $queue->push('jobs', ['job' => 'ping']);

        self::assertSame([['q:jobs', serialize(['job' => 'ping'])]], $client->rPushCalls);
    }

    public function testKeyIsPrefixPlusQueueWithoutExtraSeparator(): void
    {
        $client = new InMemoryRedisClient();
        $queue = new RedisQueue(redis: $client, prefix: 'app:queue:');

        $queue->push('emails', ['para' => 'ana@exemplo.com']);

        self::assertSame('app:queue:emails', $client->rPushCalls[0][0]);
    }

    public function testWithoutPrefixKeyIsQueueName(): void
    {
        $client = new InMemoryRedisClient();
        $queue = new RedisQueue($client);

        $queue->push('jobs', ['a' => 1]);
        $queue->pop('jobs');

        self::assertSame('jobs', $client->rPushCalls[0][0]);
        self::assertSame(['jobs'], $client->lPopCalls);
    }

    public function testPopCallsLPopOnceWithPrefixedKey(): void
    {
        $client = new InMemoryRedisClient();
        $queue = new RedisQueue(redis: $client, prefix: 'q:');

        $queue->pop('jobs');

        self::assertSame(['q:jobs'], $client->lPopCalls);
    }

    public function testPushIgnoresFalseReturnedByRPush(): void
    {
        $client = new InMemoryRedisClient(rPushResult: false);
        $queue = new RedisQueue(redis: $client, prefix: 'q:');

        $queue->push('jobs', ['a' => 1]);

        self::assertCount(1, $client->rPushCalls);
    }

    public function testIsFifo(): void
    {
        $queue = new RedisQueue(new InMemoryRedisClient(), 'q:');

        $queue->push('jobs', ['a' => 1]);
        $queue->push('jobs', ['b' => 2]);

        self::assertSame(['a' => 1], $queue->pop('jobs'));
        self::assertSame(['b' => 2], $queue->pop('jobs'));
    }

    public function testPopReturnsNullWhenQueueIsEmpty(): void
    {
        $queue = new RedisQueue(new InMemoryRedisClient(), 'q:');

        self::assertNull($queue->pop('jobs'));
    }

    public function testEmptyPayloadComesBackAsEmptyArrayNotNull(): void
    {
        $queue = new RedisQueue(new InMemoryRedisClient(), 'q:');

        $queue->push('jobs', []);

        self::assertSame([], $queue->pop('jobs'));
    }

    public function testQueuesAreSeparate(): void
    {
        $queue = new RedisQueue(new InMemoryRedisClient(), 'q:');

        $queue->push('a', ['x' => 1]);

        self::assertNull($queue->pop('b'));
        self::assertSame(['x' => 1], $queue->pop('a'));
    }

    public function testPopThrowsWhenPayloadIsSerializedString(): void
    {
        $client = new InMemoryRedisClient();
        $client->lists['q:jobs'] = [serialize('texto')];
        $queue = new RedisQueue($client, 'q:');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Payload invalido recebido da fila Redis.');

        $queue->pop('jobs');
    }

    public function testPopThrowsWhenPayloadIsSerializedInt(): void
    {
        $client = new InMemoryRedisClient();
        $client->lists['q:jobs'] = [serialize(42)];
        $queue = new RedisQueue($client, 'q:');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Payload invalido recebido da fila Redis.');

        $queue->pop('jobs');
    }

    public function testPopDoesNotInstantiateClassesInsidePayload(): void
    {
        $client = new InMemoryRedisClient();
        $client->lists['q:jobs'] = [serialize(['obj' => new ArrayObject([1])])];
        $queue = new RedisQueue($client, 'q:');

        $payload = $queue->pop('jobs');

        self::assertIsArray($payload);
        self::assertInstanceOf(__PHP_Incomplete_Class::class, $payload['obj']);
    }
}
