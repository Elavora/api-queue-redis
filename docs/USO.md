# Guia de uso

Adapter opcional de fila Redis para o framework Elavora.

Este guia é a especificação do pacote. Tudo o que um teste precisa saber está aqui.

## Versão e compatibilidade

| Item | Valor |
|---|---|
| Versão deste pacote | `1.0.0` (primeira versão compatível com o framework 2) |
| `elavora/api-framework` | `^2.0` |
| `elavora/api-redis` | `^1.0` |
| PHP | `>=8.3` |
| `ext-redis` | `*` |

A `1.0.0` sai do PR único de upgrade e adoção do pipeline central, com as labels `upgrade` e `release`. O
fluxo central sobe o major da última tag legada (`v0.2.0`, que conta como `0.2.0`) e publica a `1.0.0`
([Versões e release](AUTOMACAO.md#versões-e-release)).

Não existe compatibilidade com o framework `0.x` nem `1.x`. A versão `0.2.0` deste pacote
continua sendo a última para o framework `^0.3.1`.

### `composer.json`

O `composer.json` do pacote deve ter:

- `require.php` = `">=8.3"`
- `require.ext-redis` = `"*"`
- `require.elavora/api-framework` = `"^2.0"`
- `require.elavora/api-redis` = `"^1.0"`
- `extra.branch-alias.dev-main` = `"1.0-dev"`
- `autoload.psr-4` = `"Elavora\\Api\\Extension\\QueueRedis\\": "src/"` (sem mudança)
- `composer validate --strict --no-check-publish` sem erros.

### O que muda com o framework 2

Para este pacote, quase nada muda no código. O contrato de extensão do framework 2 é o mesmo:

- `Elavora\Api\Framework\Contracts\Extension` tem só `register(Application $application): void`.
- `Application::create()`, `Application::extend(Extension)` e `Application::container()` continuam iguais.
- O `Container` continua com `has()`, `bind()`, `instance()` e `get()`.
  `get()` guarda o objeto resolvido e devolve a mesma instância nas chamadas seguintes.
- `Elavora\Api\Framework\Contracts\Queue` continua com `push(string $queue, array $payload): void`
  e `pop(string $queue): ?array`.

A mudança real é de versão: o framework 2 exige PHP 8.3. Por isso este pacote também passa a exigir PHP 8.3.

### Registro automático pelo framework

O framework 2 registra esta extensão sozinho, em
`Elavora\Api\Framework\Bootstrap\EnvironmentExtensions::load()`.
Basta o pacote estar instalado: não existe variável `QUEUE_DRIVER`.

O framework chama `new RedisQueueExtension($config)` com este array:

| Variável | Chave no array | Valor quando vazia ou ausente |
|---|---|---|
| `REDIS_HOST` | `host` (string) | `'redis'` |
| `REDIS_PORT` | `port` (int) | `6379` |
| `REDIS_PASSWORD` | `password` (string) | `null` |
| `REDIS_DATABASE` | `database` (string, ex.: `'7'`) | `null` |
| `QUEUE_PREFIX` | `prefix` (string) | `'api:queue:'` |

Este pacote **não lê** variáveis de ambiente. Ele só recebe o array.
O construtor precisa aceitar esse formato sem erro: `database` como string numérica e a chave `prefix`.

## Instalação

```bash
composer require elavora/api-queue-redis:^1.0
```

## Quando usar

- Publicar ou consumir tarefas assíncronas.
- Esconder detalhes do backend de fila atrás dos contratos do framework.
- Reutilizar workers e handlers em ambientes diferentes.

## Exemplo rápido

```php
use Elavora\Api\Extension\QueueRedis\RedisQueueExtension;
use Elavora\Api\Framework\Contracts\Queue;

$application->extend(new RedisQueueExtension([
    'host' => 'redis',
    'prefix' => 'app:queue:',
]));

$queue = $application->container()->get(Queue::class);
$queue->push('emails', ['para' => 'ana@exemplo.com']);
$queue->pop('emails'); // ['para' => 'ana@exemplo.com']
$queue->pop('emails'); // null
```

## API pública

Namespace base: `Elavora\Api\Extension\QueueRedis`.

Tipos de outros pacotes usados aqui:

- `RedisClient`, `RedisConnectionFactory`, `RedisConfig` e `RedisServiceRegistrar` são de
  `Elavora\Api\Extension\Redis` (`elavora/api-redis` 1.0). Veja o guia daquele pacote.
- `Application`, `Container`, `Contracts\Extension` e `Contracts\Queue` são de `Elavora\Api\Framework` (framework 2).

### `RedisQueue` (classe final, implementa `Elavora\Api\Framework\Contracts\Queue`)

```php
public function __construct(RedisClient $redis, string $prefix = '');
public function push(string $queue, array $payload): void;
public function pop(string $queue): ?array;
```

O construtor aceita argumentos nomeados: `new RedisQueue(redis: $cliente, prefix: 'q:')`.

A chave Redis de uma fila é `$prefix . $queue`, sem separador extra.
Ex.: prefixo `'app:queue:'` e fila `'emails'` → chave `'app:queue:emails'`.
Sem prefixo, a chave é o próprio nome da fila.

#### `push()`

- Chama `rPush(chave, serialize($payload))` no cliente, uma vez.
- Não devolve nada. O retorno de `rPush()` é ignorado (inclusive `false`).

#### `pop()`

- Chama `lPop(chave)` no cliente, uma vez.
- `lPop()` devolveu `false` (fila vazia) → devolve `null`.
- Senão, faz `unserialize()` com `['allowed_classes' => false]`.
  - Resultado é array → devolve o array.
  - Resultado não é array → lança `UnexpectedValueException` com a mensagem
    `Payload invalido recebido da fila Redis.`

A ordem é FIFO: o primeiro `push()` é o primeiro `pop()`.

Exemplos com um cliente falso que guarda listas em memória:

| Cenário | Resultado |
|---|---|
| `prefix: 'q:'`, `push('jobs', ['job' => 'ping'])` | cliente recebe `rPush('q:jobs', serialize(['job' => 'ping']))` |
| `push('jobs', ['a' => 1])`, `push('jobs', ['b' => 2])`, depois `pop('jobs')` duas vezes | `['a' => 1]`, depois `['b' => 2]` |
| `pop('jobs')` com a fila vazia | `null` |
| `push('jobs', [])` e depois `pop('jobs')` | `[]` (array vazio, não `null`) |
| `push('a', ['x' => 1])` e depois `pop('b')` | `null` (filas separadas) |
| `pop('jobs')` quando `lPop()` devolve `serialize('texto')` | `UnexpectedValueException`, mensagem `Payload invalido recebido da fila Redis.` |
| `pop('jobs')` quando `lPop()` devolve `serialize(42)` | `UnexpectedValueException`, mesma mensagem |

Strings que não são um `serialize()` válido ficam fora desta especificação. Não teste esse caso.

### `RedisQueueExtension` (classe final, implementa `Elavora\Api\Framework\Contracts\Extension`)

```php
public function __construct(array $config);
public function register(Application $application): void;
```

#### Construtor

Aceita as chaves `host`, `port`, `timeout`, `password`, `database` e `prefix`.

- As chaves de conexão viram um `RedisConfig` por `RedisConfig::fromArray($config)`.
  As regras de normalização são as do `api-redis` 1.0. As principais:
  - `host` ausente → `'127.0.0.1'`; `port` ausente → `6379`.
  - `password` e `database` ausentes, `null` ou `''` → `null`.
  - `database` como string numérica vira int: `'7'` → `7`, `'0'` → `0`.
  - `prefix` e outras chaves desconhecidas não atrapalham.
- `prefix` é convertido para string. Ausente → `''`.
- O construtor não abre conexão.

#### `register()`

1. Chama `RedisServiceRegistrar::register($application)`. Se o container já tem
   `RedisConnectionFactory::class`, essa factory é mantida. Senão, o `api-redis` registra a dele.
2. Registra `Queue::class` no container com `bind()`.

A conexão é aberta só quando alguém pede `container()->get(Queue::class)`:

- `register()` sozinho não chama `connect()`.
- O primeiro `get(Queue::class)` chama `connect()` da factory **uma vez**, com o `RedisConfig`
  montado no construtor, e devolve um `RedisQueue` com o cliente recebido e o `prefix`.
- Chamadas seguintes de `get(Queue::class)` devolvem a mesma instância, sem novo `connect()`.

Exemplos, com uma factory falsa `$f` registrada antes por
`container()->instance(RedisConnectionFactory::class, $f)`:

| Configuração passada ao construtor | Resultado |
|---|---|
| `['host' => 'redis', 'port' => 6379, 'database' => 0, 'prefix' => 'queue:']` | `get(Queue::class)` é `RedisQueue`; `$f` chamada 1 vez com host `'redis'`, port `6379`, database `0` |
| `['host' => 'redis', 'port' => 6379, 'password' => 'secret', 'database' => '7', 'prefix' => 'api:queue:']` (formato do framework 2) | `$f` recebe password `'secret'` e database `7` (int) |
| `['host' => 'redis', 'port' => 6379, 'password' => null, 'database' => null, 'prefix' => 'api:queue:']` | `$f` recebe password `null` e database `null` |
| `[]` | `$f` recebe host `'127.0.0.1'`, port `6379` |
| só `extend()`, sem `get(Queue::class)` | `$f` chamada 0 vezes |
| `get(Queue::class)` duas vezes | mesma instância; `$f` chamada 1 vez |
| `['prefix' => 'queue:']`, `push('jobs', ['a' => 1])` pela fila do container | o cliente devolvido por `$f` recebe `rPush('queue:jobs', ...)` |
| sem `prefix`, `push('jobs', ['a' => 1])` | o cliente recebe `rPush('jobs', ...)` |

Sem factory registrada antes, `get(RedisConnectionFactory::class)` depois do `extend()` é um
`Elavora\Api\Extension\Redis\RedisConnectionManager`. Pedir `get(Queue::class)` nesse caso abre
uma conexão real com `ext-redis`, então isso fica fora dos testes unitários.

#### Binding inválido

Se o binding de `RedisConnectionFactory::class` resolver um valor que não implementa a interface, o
`get(Queue::class)` lança `LogicException` (e não um erro fatal do PHP), com a mensagem exata:

```text
O binding de Elavora\Api\Extension\Redis\Contracts\RedisConnectionFactory deve resolver uma instancia dessa interface.
```

- A mensagem não tem acento em `instancia`.
- O erro aparece só ao resolver a `Queue`: `register()` não resolve a factory e não lança exceção.
- Nenhuma conexão é aberta e nenhum `RedisQueue` é criado.

| Cenário | Resultado |
|---|---|
| `instance(RedisConnectionFactory::class, new stdClass())`, `extend(new RedisQueueExtension([]))`, `get(Queue::class)` | `LogicException` com a mensagem acima |
| `instance(RedisConnectionFactory::class, ...)` com uma `RedisConnectionFactory` válida | sem erro (cenários da seção anterior) |
## Validação local

Os testes rodam no container `ghcr.io/elavora/php-dev:8.3-latest`, que já traz `ext-redis`. Os testes unitários usam
factory e cliente falsos: não precisam de servidor Redis. O teste com Redis real (`tests/RedisQueueTest.php`) só roda
quando há `ext-redis` **e** um servidor Redis acessível em `REDIS_HOST` (padrão `redis`) e `REDIS_PORT` (padrão
`6379`). Sem servidor, ele é pulado (`markTestSkipped`) e não falha a suíte. Veja
[`ext-redis` e os testes no CI](AUTOMACAO.md#ext-redis-e-os-testes-no-ci).

O comando completo, igual ao do CI, está em [Desenvolvimento e testes](DESENVOLVIMENTO.md#validar-como-o-ci). Para só
rodar a suíte, no PowerShell, na raiz do repo:

```powershell
docker run --rm --mount "type=bind,source=$($PWD.Path),target=/workspace" -w /workspace ghcr.io/elavora/php-dev:8.3-latest sh -lc 'git config --global --add safe.directory /workspace && composer install --no-interaction --prefer-dist && composer test'
```
## Observações

- Mantenha regras de produto fora deste pacote.
- Prefira configurar extensões no bootstrap da aplicação.
- Instale apenas os módulos que a aplicação realmente usa.
