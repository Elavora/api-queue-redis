<?php

declare(strict_types=1);

use Elavora\Api\Extension\QueueRedis\ReliableRedisQueue;
use Elavora\Api\Framework\Contracts\AcknowledgesJobs;
use Elavora\Api\Framework\Contracts\Queue;
use Elavora\Api\Framework\Queue\ReservedJob;
use PHPUnit\Framework\TestCase;

final class ReliableRedisQueueTest extends TestCase
{
    private InMemoryListRedisClient $client;

    protected function setUp(): void
    {
        $this->client = new InMemoryListRedisClient();
    }

    private function queue(int $timeout = 300, string $prefix = 'q:'): ReliableRedisQueue
    {
        return new ReliableRedisQueue(redis: $this->client, prefix: $prefix, visibilityTimeout: $timeout);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function lease(string $key, array $payload): string
    {
        return $key . ':lease:' . sha1(serialize($payload));
    }

    public function testImplementsQueueAndAcknowledgesJobsAndIsFinal(): void
    {
        $queue = $this->queue();

        self::assertInstanceOf(Queue::class, $queue);
        self::assertInstanceOf(AcknowledgesJobs::class, $queue);
        self::assertTrue((new ReflectionClass(ReliableRedisQueue::class))->isFinal());
    }

    public function testRejectsVisibilityTimeoutBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('O prazo de visibilidade deve ser maior que zero.');

        $this->queue(0);
    }

    public function testPushAndPopBehaveLikeRedisQueue(): void
    {
        $queue = $this->queue();

        $queue->push('jobs', ['a' => 1]);
        $queue->push('jobs', ['b' => 2]);

        self::assertSame([serialize(['a' => 1]), serialize(['b' => 2])], $this->client->lists['q:jobs']);
        self::assertSame(['a' => 1], $queue->pop('jobs'));
        self::assertSame(['b' => 2], $queue->pop('jobs'));
        self::assertNull($queue->pop('jobs'));
        self::assertArrayNotHasKey('q:jobs:processing', $this->client->lists);
    }

    public function testPopRejectsInvalidPayload(): void
    {
        $this->client->rPush('q:jobs', serialize(42));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Payload invalido recebido da fila Redis.');

        $this->queue()->pop('jobs');
    }

    public function testReserveMovesJobToProcessingAndSetsLease(): void
    {
        $queue = $this->queue(timeout: 120);
        $queue->push('jobs', ['a' => 1]);

        $job = $queue->reserve(['jobs']);

        self::assertInstanceOf(ReservedJob::class, $job);
        self::assertSame('jobs', $job->queue());
        self::assertSame(['a' => 1], $job->payload());
        self::assertSame([], $this->client->lists['q:jobs']);
        self::assertSame([serialize(['a' => 1])], $this->client->lists['q:jobs:processing']);
        self::assertSame(120, $this->client->ttls[$this->lease('q:jobs', ['a' => 1])]);
    }

    public function testReserveReturnsNullWhenAllQueuesAreEmpty(): void
    {
        self::assertNull($this->queue()->reserve(['a', 'b']));
    }

    public function testReserveRejectsEmptyQueueList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A lista de filas nao pode ser vazia.');

        $this->queue()->reserve([]);
    }

    public function testReserveFollowsPriorityOrder(): void
    {
        $queue = $this->queue();
        $queue->push('tags', ['t' => 1]);
        $queue->push('descricao', ['d' => 1]);
        $queue->push('conteudo', ['c' => 1]);
        $order = ['conteudo', 'descricao', 'tags'];

        self::assertSame(['c' => 1], $queue->reserve($order)?->payload());
        self::assertSame(['d' => 1], $queue->reserve($order)?->payload());
        self::assertSame(['t' => 1], $queue->reserve($order)?->payload());
        self::assertNull($queue->reserve($order));
    }

    public function testReserveSkipsEmptyHigherPriorityQueue(): void
    {
        $queue = $this->queue();
        $queue->push('tags', ['t' => 1]);

        $job = $queue->reserve(['conteudo', 'tags']);

        self::assertSame('tags', $job?->queue());
    }

    public function testReserveKeepsFifoWithinQueue(): void
    {
        $queue = $this->queue();
        $queue->push('jobs', ['n' => 1]);
        $queue->push('jobs', ['n' => 2]);

        self::assertSame(['n' => 1], $queue->reserve(['jobs'])?->payload());
        self::assertSame(['n' => 2], $queue->reserve(['jobs'])?->payload());
    }

    public function testReserveDiscardsInvalidPayloadAndThrows(): void
    {
        $this->client->rPush('q:jobs', serialize('texto'));

        try {
            $this->queue()->reserve(['jobs']);
            self::fail('Era esperada UnexpectedValueException.');
        } catch (UnexpectedValueException $error) {
            self::assertSame('Payload invalido recebido da fila Redis.', $error->getMessage());
        }

        self::assertSame([], $this->client->lists['q:jobs:processing']);
        self::assertSame([], $this->client->values);
    }

    public function testAcknowledgeRemovesJobAndLease(): void
    {
        $queue = $this->queue();
        $queue->push('jobs', ['a' => 1]);
        $job = $queue->reserve(['jobs']);
        self::assertNotNull($job);

        $queue->acknowledge($job);

        self::assertSame([], $this->client->lists['q:jobs:processing']);
        self::assertSame([], $this->client->values);
        self::assertNull($queue->reserve(['jobs']));
    }

    public function testAcknowledgeTwiceDoesNothing(): void
    {
        $queue = $this->queue();
        $queue->push('jobs', ['a' => 1]);
        $job = $queue->reserve(['jobs']);
        self::assertNotNull($job);

        $queue->acknowledge($job);
        $queue->acknowledge($job);

        self::assertSame([], $this->client->lists['q:jobs:processing']);
    }

    public function testAcknowledgeKeepsLeaseWhileIdenticalJobIsStillProcessing(): void
    {
        $queue = $this->queue();
        $queue->push('jobs', ['a' => 1]);
        $queue->push('jobs', ['a' => 1]);
        $first = $queue->reserve(['jobs']);
        $second = $queue->reserve(['jobs']);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $queue->acknowledge($first);

        self::assertCount(1, $this->client->lists['q:jobs:processing']);
        self::assertArrayHasKey($this->lease('q:jobs', ['a' => 1]), $this->client->values);

        $queue->acknowledge($second);

        self::assertSame([], $this->client->values);
    }

    public function testExtendRenewsLeaseWithDefaultTimeout(): void
    {
        $queue = $this->queue(timeout: 90);
        $queue->push('jobs', ['a' => 1]);
        $job = $queue->reserve(['jobs']);
        self::assertNotNull($job);
        $this->client->setexCalls = [];

        $queue->extend($job);

        self::assertSame([[$this->lease('q:jobs', ['a' => 1]), 90, '1']], $this->client->setexCalls);
    }

    public function testExtendRenewsLeaseWithExplicitSeconds(): void
    {
        $queue = $this->queue(timeout: 90);
        $queue->push('jobs', ['a' => 1]);
        $job = $queue->reserve(['jobs']);
        self::assertNotNull($job);

        $queue->extend($job, 3600);

        self::assertSame(3600, $this->client->ttls[$this->lease('q:jobs', ['a' => 1])]);
    }

    public function testExtendRejectsSecondsBelowOne(): void
    {
        $queue = $this->queue();
        $job = new ReservedJob('jobs', ['a' => 1], serialize(['a' => 1]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('O prazo de visibilidade deve ser maior que zero.');

        $queue->extend($job, 0);
    }

    public function testExtendDoesNothingWhenJobIsNoLongerProcessing(): void
    {
        $queue = $this->queue();
        $queue->push('jobs', ['a' => 1]);
        $job = $queue->reserve(['jobs']);
        self::assertNotNull($job);
        $queue->acknowledge($job);
        $this->client->setexCalls = [];

        $queue->extend($job);

        self::assertSame([], $this->client->setexCalls);
        self::assertSame([], $this->client->values);
    }

    public function testRecoverExpiredReturnsJobsWithoutLeaseToTheFrontInOriginalOrder(): void
    {
        $queue = $this->queue();
        $queue->push('jobs', ['n' => 1]);
        $queue->push('jobs', ['n' => 2]);
        $queue->push('jobs', ['n' => 3]);
        $queue->reserve(['jobs']);
        $queue->reserve(['jobs']);
        $this->client->expire($this->lease('q:jobs', ['n' => 1]));
        $this->client->expire($this->lease('q:jobs', ['n' => 2]));

        self::assertSame(2, $queue->recoverExpired('jobs'));

        self::assertSame(
            [serialize(['n' => 1]), serialize(['n' => 2]), serialize(['n' => 3])],
            $this->client->lists['q:jobs'],
        );
        self::assertSame([], $this->client->lists['q:jobs:processing']);
    }

    public function testRecoverExpiredKeepsJobsWithLiveLease(): void
    {
        $queue = $this->queue();
        $queue->push('jobs', ['n' => 1]);
        $queue->push('jobs', ['n' => 2]);
        $queue->reserve(['jobs']);
        $queue->reserve(['jobs']);
        $this->client->expire($this->lease('q:jobs', ['n' => 2]));

        self::assertSame(1, $queue->recoverExpired('jobs'));

        self::assertSame([serialize(['n' => 2])], $this->client->lists['q:jobs']);
        self::assertSame([serialize(['n' => 1])], $this->client->lists['q:jobs:processing']);
    }

    public function testRecoverExpiredReturnsZeroWhenNothingExpired(): void
    {
        self::assertSame(0, $this->queue()->recoverExpired('jobs'));
    }

    public function testReserveRecoversExpiredJobsBeforeReserving(): void
    {
        $queue = $this->queue();
        $queue->push('jobs', ['n' => 1]);
        $lost = $queue->reserve(['jobs']);
        self::assertNotNull($lost);
        $this->client->expire($this->lease('q:jobs', ['n' => 1]));

        $again = $queue->reserve(['jobs']);

        self::assertSame(['n' => 1], $again?->payload());
        self::assertSame([serialize(['n' => 1])], $this->client->lists['q:jobs:processing']);
        self::assertArrayHasKey($this->lease('q:jobs', ['n' => 1]), $this->client->values);
    }

    public function testWorksWithoutPrefix(): void
    {
        $queue = $this->queue(prefix: '');
        $queue->push('jobs', ['a' => 1]);

        $queue->reserve(['jobs']);

        self::assertArrayHasKey('jobs:processing', $this->client->lists);
    }
}
