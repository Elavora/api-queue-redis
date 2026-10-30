<?php

declare(strict_types=1);

use Elavora\Api\Extension\Redis\Contracts\RedisClient;

/**
 * Cliente Redis falso que guarda listas em memória e registra as chamadas.
 */
final class InMemoryRedisClient implements RedisClient
{
    /** @var array<string, list<string>> */
    public array $lists = [];

    /** @var list<array{0: string, 1: string}> */
    public array $rPushCalls = [];

    /** @var list<string> */
    public array $lPopCalls = [];

    public function __construct(private readonly int|false|null $rPushResult = null) {}

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
        $count = 0;
        foreach ($keys as $key) {
            if (isset($this->lists[$key])) {
                unset($this->lists[$key]);
                $count++;
            }
        }

        return $count;
    }

    public function rPush(string $key, string $value): int|false
    {
        $this->rPushCalls[] = [$key, $value];
        $this->lists[$key][] = $value;

        return $this->rPushResult ?? count($this->lists[$key]);
    }

    public function lPop(string $key): string|false
    {
        $this->lPopCalls[] = $key;
        if (empty($this->lists[$key])) {
            return false;
        }

        return array_shift($this->lists[$key]);
    }
}
