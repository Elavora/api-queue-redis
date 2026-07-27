# Guia de uso

## Instalacao

```bash
composer require elavora/api-queue-redis:^1.0
```

Requisitos de runtime:

- PHP `>=8.3`
- `ext-redis`
- `elavora/api-framework` `^1.0`
- `elavora/api-redis` `^1.0`

## Registro

```php
use Elavora\Api\Extension\QueueRedis\RedisQueueExtension;

$application->extend(new RedisQueueExtension([
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => getenv('REDIS_PORT') ?: '6379',
    'password' => getenv('REDIS_PASSWORD') ?: null,
    'database' => getenv('REDIS_DATABASE') ?: '0',
    'prefix' => 'app:queue:',
]));
```

As opcoes de conexao seguem as validacoes de `RedisConfig`, e `prefix` deve ser
string.

`push()` considera somente um retorno inteiro positivo de `rPush` como
sucesso. `false` e zero geram `RuntimeException`; a mensagem identifica a fila
sem incluir o payload. `pop()` retorna `null` para fila vazia e rejeita payload
que nao respeite o formato `array<string, mixed>`.

## Qualidade

```bash
composer validate --strict --no-check-publish
composer lint
composer analyse
composer test
composer check
```

`composer check` executa lint portatil, PHPStan nivel 8 e PHPUnit.
