<?php

declare(strict_types=1);

use Elavora\Api\Extension\QueueRedis\RedisQueue;
use Elavora\Api\Extension\QueueRedis\RedisQueueExtension;
use Elavora\Api\Extension\QueueRedis\ReliableRedisQueue;
use Elavora\Api\Framework\Contracts\AcknowledgesJobs;
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

    public function testInvalidFactoryBindingThrowsLogicExceptionOnlyWhenResolvingQueue(): void
    {
        $application = Application::create();
        $application->container()->instance(RedisConnectionFactory::class, new stdClass());

        $application->extend(new RedisQueueExtension([]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'O binding de Elavora\Api\Extension\Redis\Contracts\RedisConnectionFactory deve resolver uma instancia dessa interface.',
        );

        $application->container()->get(Queue::class);
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

    /**
     * @param array<string, mixed> $config
     */
    private function bootWithListClient(array $config): Queue
    {
        $client = new InMemoryListRedisClient();
        $application = Application::create();
        $application->container()->instance(RedisConnectionFactory::class, new CountingConnectionFactory($client));
        $application->extend(new RedisQueueExtension($config));

        return $application->container()->get(Queue::class);
    }

    public function testBindsReliableQueueWhenClientSupportsListOperations(): void
    {
        $queue = $this->bootWithListClient(['prefix' => 'q:']);

        self::assertInstanceOf(ReliableRedisQueue::class, $queue);
        self::assertInstanceOf(AcknowledgesJobs::class, $queue);
    }

    public function testBindsPlainQueueWhenClientOnlySupportsRedisClient(): void
    {
        [$application] = $this->boot(['prefix' => 'q:']);
        $queue = $application->container()->get(Queue::class);

        self::assertInstanceOf(RedisQueue::class, $queue);
        self::assertNotInstanceOf(AcknowledgesJobs::class, $queue);
    }

    public function testVisibilityTimeoutDefaultsTo300(): void
    {
        $queue = $this->bootWithListClient([]);
        self::assertInstanceOf(ReliableRedisQueue::class, $queue);
        $queue->push('jobs', ['a' => 1]);
        $queue->reserve(['jobs']);

        $client = (new ReflectionProperty($queue, 'redis'))->getValue($queue);
        self::assertInstanceOf(InMemoryListRedisClient::class, $client);
        self::assertSame(300, array_values($client->ttls)[0]);
    }

    public function testVisibilityTimeoutAcceptsIntAndNumericString(): void
    {
        foreach ([600, '600'] as $value) {
            $queue = $this->bootWithListClient(['visibility_timeout' => $value]);
            self::assertInstanceOf(ReliableRedisQueue::class, $queue);
            $queue->push('jobs', ['a' => 1]);
            $queue->reserve(['jobs']);

            $client = (new ReflectionProperty($queue, 'redis'))->getValue($queue);
            self::assertInstanceOf(InMemoryListRedisClient::class, $client);
            self::assertSame(600, array_values($client->ttls)[0]);
        }
    }

    public function testNullVisibilityTimeoutUsesDefault(): void
    {
        self::assertInstanceOf(ReliableRedisQueue::class, $this->bootWithListClient(['visibility_timeout' => null]));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidVisibilityTimeouts(): array
    {
        return ['zero' => [0], 'negativo' => [-1], 'texto' => ['abc'], 'zero em texto' => ['0'], 'decimal' => ['1.5']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidVisibilityTimeouts')]
    public function testInvalidVisibilityTimeoutThrowsInConstructor(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('visibility_timeout deve ser um inteiro maior que zero.');

        new RedisQueueExtension(['visibility_timeout' => $value]);
    }
}
