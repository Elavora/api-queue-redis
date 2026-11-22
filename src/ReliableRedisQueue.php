<?php

declare(strict_types=1);

namespace Elavora\Api\Extension\QueueRedis;

use Elavora\Api\Extension\Redis\Contracts\RedisListOperations;
use Elavora\Api\Framework\Contracts\AcknowledgesJobs;
use Elavora\Api\Framework\Contracts\Queue;
use Elavora\Api\Framework\Queue\ReservedJob;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Fila Redis com confirmacao (ack) e prazo de visibilidade.
 *
 * Fila `X` usa a lista `X` (esperando), a lista `X:processing` (reservadas) e uma chave
 * `X:lease:<sha1>` com TTL para o prazo de cada mensagem reservada.
 */
final class ReliableRedisQueue implements Queue, AcknowledgesJobs
{
    /**
     * @param RedisListOperations $redis Cliente Redis com operacoes de lista.
     * @param string $prefix Prefixo aplicado nas filas.
     * @param int $visibilityTimeout Prazo padrao de visibilidade, em segundos.
     */
    public function __construct(
        private readonly RedisListOperations $redis,
        private readonly string $prefix = '',
        private readonly int $visibilityTimeout = 300,
    ) {
        self::assertValidTimeout($visibilityTimeout);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function push(string $queue, array $payload): void
    {
        $this->redis->rPush($this->queueKey($queue), serialize($payload));
    }

    /**
     * Remove e retorna o proximo payload, sem reserva nem ack.
     *
     * @return array<string, mixed>|null
     */
    public function pop(string $queue): ?array
    {
        $message = $this->redis->lPop($this->queueKey($queue));

        if ($message === false) {
            return null;
        }

        return self::decode($message);
    }

    public function reserve(array $queues): ?ReservedJob
    {
        if ($queues === []) {
            throw new InvalidArgumentException('A lista de filas nao pode ser vazia.');
        }

        foreach ($queues as $queue) {
            $this->recoverExpired($queue);

            $message = $this->redis->lMove($this->queueKey($queue), $this->processingKey($queue), 'LEFT', 'RIGHT');

            if ($message === false) {
                continue;
            }

            $this->redis->setex($this->leaseKey($queue, $message), $this->visibilityTimeout, '1');

            try {
                $payload = self::decode($message);
            } catch (UnexpectedValueException $error) {
                $this->release($queue, $message);

                throw $error;
            }

            return new ReservedJob($queue, $payload, $message);
        }

        return null;
    }

    public function acknowledge(ReservedJob $job): void
    {
        $this->release($job->queue(), $job->token());
    }

    public function extend(ReservedJob $job, ?int $seconds = null): void
    {
        $seconds ??= $this->visibilityTimeout;
        self::assertValidTimeout($seconds);

        if (!$this->isProcessing($job->queue(), $job->token())) {
            return;
        }

        $this->redis->setex($this->leaseKey($job->queue(), $job->token()), $seconds, '1');
    }

    public function recoverExpired(string $queue): int
    {
        $processing = $this->redis->lRange($this->processingKey($queue), 0, -1);

        if ($processing === false || $processing === []) {
            return 0;
        }

        $recovered = 0;

        // Da mais nova para a mais antiga: cada lPush vai para o inicio, entao a mais antiga fica na frente.
        foreach (array_reverse($processing) as $message) {
            if ($this->redis->get($this->leaseKey($queue, $message)) !== false) {
                continue;
            }

            if ($this->redis->lRem($this->processingKey($queue), $message, 1) > 0) {
                $this->redis->lPush($this->queueKey($queue), $message);
                $recovered++;
            }
        }

        return $recovered;
    }

    /**
     * Tira a mensagem da lista de processamento e apaga o lease quando nao sobra copia identica.
     */
    private function release(string $queue, string $message): void
    {
        $this->redis->lRem($this->processingKey($queue), $message, 1);

        if (!$this->isProcessing($queue, $message)) {
            $this->redis->del($this->leaseKey($queue, $message));
        }
    }

    private function isProcessing(string $queue, string $message): bool
    {
        $processing = $this->redis->lRange($this->processingKey($queue), 0, -1);

        return is_array($processing) && in_array($message, $processing, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $message): array
    {
        $value = unserialize($message, ['allowed_classes' => false]);

        if (!is_array($value)) {
            throw new UnexpectedValueException('Payload invalido recebido da fila Redis.');
        }

        return $value;
    }

    private static function assertValidTimeout(int $seconds): void
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('O prazo de visibilidade deve ser maior que zero.');
        }
    }

    private function queueKey(string $queue): string
    {
        return $this->prefix . $queue;
    }

    private function processingKey(string $queue): string
    {
        return $this->queueKey($queue) . ':processing';
    }

    private function leaseKey(string $queue, string $message): string
    {
        return $this->queueKey($queue) . ':lease:' . sha1($message);
    }
}
