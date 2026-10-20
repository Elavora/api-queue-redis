# elavora/api-queue-redis

Adapter opcional de fila Redis para o framework Elavora.

Versão `1.0`: exige `elavora/api-framework` `^2.0`, `elavora/api-redis` `^1.0` e PHP `>=8.3`.
Para o framework `^0.3.1`, use a versão `0.2.0`.

Com o pacote instalado, o framework 2 registra a fila sozinho a partir das variáveis
`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DATABASE` e `QUEUE_PREFIX`.

Para registrar à mão, use `RedisQueueExtension` com as opções `host`, `port`, `timeout`,
`password`, `database` e `prefix`.

Este pacote usa `elavora/api-redis` para abrir e reutilizar conexões Redis. Se
outra extensão registrar uma implementação própria de `RedisConnectionFactory`,
a fila passa a usar essa factory automaticamente.

Especificação completa, exemplos e validação local: [docs/USO.md](docs/USO.md).
