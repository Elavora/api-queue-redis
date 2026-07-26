# elavora/api-queue-redis

Adapter opcional de fila Redis para o framework Elavora.

## Requisitos

- PHP `>=8.3`
- `ext-redis`
- `elavora/api-framework` `^1.0`
- `elavora/api-redis` `^1.0`

```php
use Elavora\Api\Extension\QueueRedis\RedisQueueExtension;

$application->extend(new RedisQueueExtension([
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => getenv('REDIS_PORT') ?: '6379',
    'prefix' => 'app:queue:',
]));
```

O adapter propaga falhas de `rPush` como `RuntimeException`. A excecao
identifica a fila, mas nunca inclui o payload serializado.

Consulte [docs/USO.md](docs/USO.md) para configuracao e validacao.
