<?php

declare(strict_types=1);

use Elavora\Api\Extension\Redis\Contracts\RedisListOperations;

/**
 * Cliente Redis falso com operações de lista e TTL simulado (expire() faz a chave sumir).
 */
final class InMemoryListRedisClient implements RedisListOperations
{
    /** @var array<string, list<string>> */
    public array $lists = [];

    /** @var array<string, string> */
    public array $values = [];

    /** @var array<string, int> */
    public array $ttls = [];

    /** @var list<array{0: string, 1: int, 2: string}> */
    public array $setexCalls = [];

    public function get(string $key): string|false
    {
        return $this->values[$key] ?? false;
    }

    public function set(string $key, string $value): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function setex(string $key, int $ttlSeconds, string $value): bool
    {
        $this->setexCalls[] = [$key, $ttlSeconds, $value];
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttlSeconds;

        return true;
    }

    public function del(string ...$keys): int|false
    {
        $count = 0;
        foreach ($keys as $key) {
            if (isset($this->values[$key])) {
                unset($this->values[$key], $this->ttls[$key]);
                $count++;
            }
            if (isset($this->lists[$key])) {
                unset($this->lists[$key]);
                $count++;
            }
        }

        return $count;
    }

    /** Simula a expiração do TTL de uma chave. */
    public function expire(string $key): void
    {
        unset($this->values[$key], $this->ttls[$key]);
    }

    public function rPush(string $key, string $value): int|false
    {
        $this->lists[$key][] = $value;

        return count($this->lists[$key]);
    }

    public function lPop(string $key): string|false
    {
        if (($this->lists[$key] ?? []) === []) {
            return false;
        }

        return array_shift($this->lists[$key]);
    }

    public function lPush(string $key, string $value): int|false
    {
        $this->lists[$key] ??= [];
        array_unshift($this->lists[$key], $value);

        return count($this->lists[$key]);
    }

    public function lMove(string $source, string $destination, string $from, string $to): string|false
    {
        if (($this->lists[$source] ?? []) === []) {
            return false;
        }

        $item = $from === 'LEFT' ? array_shift($this->lists[$source]) : array_pop($this->lists[$source]);
        $this->lists[$destination] ??= [];

        if ($to === 'LEFT') {
            array_unshift($this->lists[$destination], $item);
        } else {
            $this->lists[$destination][] = $item;
        }

        return $item;
    }

    public function lRem(string $key, string $value, int $count): int|false
    {
        $removed = 0;
        foreach ($this->lists[$key] ?? [] as $index => $item) {
            if ($item === $value && ($count === 0 || $removed < $count)) {
                unset($this->lists[$key][$index]);
                $removed++;
            }
        }
        if (isset($this->lists[$key])) {
            $this->lists[$key] = array_values($this->lists[$key]);
        }

        return $removed;
    }

    public function lRange(string $key, int $start, int $stop): array|false
    {
        $list = $this->lists[$key] ?? [];

        return $stop === -1 ? array_slice($list, $start) : array_slice($list, $start, $stop - $start + 1);
    }
}
