# elavora/api-queue-redis

[![Packagist Version](https://img.shields.io/packagist/v/elavora/api-queue-redis.svg?style=flat-square)](https://packagist.org/packages/elavora/api-queue-redis)
[![PHP Version](https://img.shields.io/packagist/php-v/elavora/api-queue-redis.svg?style=flat-square)](https://packagist.org/packages/elavora/api-queue-redis)
[![Composer Quality](https://github.com/Elavora/api-queue-redis/actions/workflows/quality.yml/badge.svg?branch=main)](https://github.com/Elavora/api-queue-redis/actions/workflows/quality.yml)
[![CodeQL](https://github.com/Elavora/api-queue-redis/actions/workflows/codeql.yml/badge.svg?branch=main)](https://github.com/Elavora/api-queue-redis/actions/workflows/codeql.yml)
[![License](https://img.shields.io/packagist/l/elavora/api-queue-redis.svg?style=flat-square)](LICENSE)
Adapter opcional de fila Redis para o framework Elavora.

Registre `RedisQueueExtension` com as opcoes `host`, `port`, `timeout`,
`password`, `database` e `prefix` conforme a necessidade da aplicacao.

Este pacote usa `elavora/api-redis` para abrir e reutilizar conexoes Redis. Se
outra extensao registrar uma implementacao propria de `RedisConnectionFactory`,
a fila passa a usar essa factory automaticamente.
