<?php

declare(strict_types=1);

use Elavora\Api\Extension\QueueRedis\RedisQueue;
use Elavora\Api\Extension\QueueRedis\RedisQueueExtension;
use Elavora\Api\Extension\Redis\Contracts\RedisConnectionFactory;
use Elavora\Api\Extension\Redis\RedisConnectionManager;
use Elavora\Api\Framework\Application;
use Elavora\Api\Framework\Contracts\Extension;
use Elavora\Api\Framework\Contracts\Queue;
use PHPUnit\Framework\TestCase;

final class RedisQueueExtensionTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     * @return array{0: Application, 1: CountingConnectionFactory}
     */
    private function boot(array $config): array
    {
        $factory = new CountingConnectionFactory();
        $application = Application::create();
        $application->container()->instance(RedisConnectionFactory::class, $factory);
        $application->extend(new RedisQueueExtension($config));

        return [$application, $factory];
    }

    public function testImplementsExtensionContractAndIsFinal(): void
    {
        self::assertInstanceOf(Extension::class, new RedisQueueExtension([]));
        self::assertTrue((new ReflectionClass(RedisQueueExtension::class))->isFinal());
    }

    public function testUsesSharedRedisConnectionFactory(): void
    {
        [$application, $factory] = $this->boot([
            'host' => 'redis',
            'port' => 6379,
            'database' => 0,
            'prefix' => 'queue:',
        ]);

        self::assertInstanceOf(RedisQueue::class, $application->container()->get(Queue::class));
        self::assertSame(1, $factory->connections);
        self::assertSame('redis', $factory->lastConfig?->host);
        self::assertSame(6379, $factory->lastConfig?->port);
        self::assertSame(0, $factory->lastConfig?->database);
    }

    public function testAcceptsFramework2ConfigFormat(): void
    {
        [$application, $factory] = $this->boot([
            'host' => 'redis',
            'port' => 6379,
            'password' => 'secret',
            'database' => '7',
            'prefix' => 'api:queue:',
        ]);

        $application->container()->get(Queue::class);

        self::assertSame(1, $factory->connections);
        self::assertSame('secret', $factory->lastConfig?->password);
        self::assertSame(7, $factory->lastConfig?->database);
    }

    public function testNullPasswordAndDatabaseStayNull(): void
    {
        [$application, $factory] = $this->boot([
            'host' => 'redis',
            'port' => 6379,
            'password' => null,
            'database' => null,
            'prefix' => 'api:queue:',
        ]);

        $application->container()->get(Queue::class);

        self::assertNull($factory->lastConfig?->password);
        self::assertNull($factory->lastConfig?->database);
    }

    public function testEmptyConfigUsesRedisConfigDefaults(): void
    {
        [$application, $factory] = $this->boot([]);

        $application->container()->get(Queue::class);

        self::assertSame(1, $factory->connections);
        self::assertSame('127.0.0.1', $factory->lastConfig?->host);
        self::assertSame(6379, $factory->lastConfig?->port);
    }

    public function testConstructorDoesNotConnect(): void
    {
        $factory = new CountingConnectionFactory();
        $application = Application::create();
        $application->container()->instance(RedisConnectionFactory::class, $factory);

        new RedisQueueExtension(['host' => 'redis']);

        self::assertSame(0, $factory->connections);
    }

    public function testRegisterAloneDoesNotConnect(): void
    {
        [, $factory] = $this->boot(['host' => 'redis', 'prefix' => 'queue:']);

        self::assertSame(0, $factory->connections);
    }

    public function testRepeatedGetReturnsSameInstanceWithSingleConnection(): void
    {
        [$application, $factory] = $this->boot(['host' => 'redis', 'prefix' => 'queue:']);

        $first = $application->container()->get(Queue::class);
        $second = $application->container()->get(Queue::class);

        self::assertSame($first, $second);
        self::assertSame(1, $factory->connections);
    }

    public function testKeepsFactoryRegisteredBeforeExtend(): void
    {
        [$application, $factory] = $this->boot(['prefix' => 'queue:']);

        self::assertSame($factory, $application->container()->get(RedisConnectionFactory::class));
    }

    public function testQueueUsesClientFromFactoryWithPrefix(): void
    {
        [$application, $factory] = $this->boot(['prefix' => 'queue:']);

        $application->container()->get(Queue::class)->push('jobs', ['a' => 1]);

        self::assertSame([['queue:jobs', serialize(['a' => 1])]], $factory->client->rPushCalls);
    }

    public function testQueueWithoutPrefixUsesQueueNameAsKey(): void
    {
        [$application, $factory] = $this->boot([]);

        $application->container()->get(Queue::class)->push('jobs', ['a' => 1]);

        self::assertSame('jobs', $factory->client->rPushCalls[0][0] ?? null);
    }

    public function testQueueFromContainerRoundTripsThroughFakeClient(): void
    {
        [$application] = $this->boot(['prefix' => 'app:queue:']);
        $queue = $application->container()->get(Queue::class);

        $queue->push('emails', ['para' => 'ana@exemplo.com']);

        self::assertSame(['para' => 'ana@exemplo.com'], $queue->pop('emails'));
        self::assertNull($queue->pop('emails'));
    }

    public function testRegistersRedisConnectionManagerWhenNoFactoryWasRegistered(): void
    {
        $application = Application::create();
        $application->extend(new RedisQueueExtension(['host' => 'redis']));

        self::assertInstanceOf(
            RedisConnectionManager::class,
            $application->container()->get(RedisConnectionFactory::class),
        );
    }
}
