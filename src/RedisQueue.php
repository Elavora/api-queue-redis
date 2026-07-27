<?php

declare(strict_types=1);

namespace Elavora\Api\Extension\QueueRedis;

use Elavora\Api\Extension\Redis\Contracts\RedisClient;
use Elavora\Api\Framework\Contracts\Queue;
use RuntimeException;
use UnexpectedValueException;

final class RedisQueue implements Queue
{
    /**
     * @param RedisClient $redis Cliente Redis reutilizavel.
     * @param string $prefix Prefixo aplicado nas filas.
     */
    public function __construct(
        private readonly RedisClient $redis,
        private readonly string $prefix = ''
    ) {
    }

    /**
     * Enfileira um payload serializavel.
     *
     * @param array<string, mixed> $payload
     */
    public function push(string $queue, array $payload): void
    {
        $result = $this->redis->rPush($this->queueKey($queue), serialize($payload));

        if ($result === false || $result <= 0) {
            throw new RuntimeException("Falha ao enfileirar mensagem na fila Redis '{$queue}'.");
        }
    }

    /**
     * Remove e retorna o proximo payload da fila.
     *
     * @return array<string, mixed>|null
     */
    public function pop(string $queue): ?array
    {
        $payload = $this->redis->lPop($this->queueKey($queue));

        if ($payload === false) {
            return null;
        }

        $value = unserialize($payload, ['allowed_classes' => false]);

        if (!is_array($value) || !$this->hasOnlyStringKeys($value)) {
            throw new UnexpectedValueException('Payload invalido recebido da fila Redis.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function queueKey(string $queue): string
    {
        return $this->prefix . $queue;
    }

    /**
     * @param array<mixed, mixed> $value
     */
    private function hasOnlyStringKeys(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }
}
