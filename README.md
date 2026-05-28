# redis

Asynchronous Redis client for PHP, built on Workerman.

[![CI](https://github.com/detain/redis/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/redis/actions/workflows/ci.yml)
[![Codecov](https://codecov.io/gh/detain/redis/branch/master/graph/badge.svg)](https://codecov.io/gh/detain/redis)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/detain/redis)](https://app.codacy.com/gh/detain/redis/dashboard)
[![Codacy Coverage](https://app.codacy.com/project/badge/Coverage/detain/redis)](https://app.codacy.com/gh/detain/redis/dashboard)
[![Latest Stable Version](https://poser.pugx.org/workerman/redis/v/stable)](https://packagist.org/packages/workerman/redis)
[![Total Downloads](https://poser.pugx.org/workerman/redis/downloads)](https://packagist.org/packages/workerman/redis)
[![License](https://poser.pugx.org/workerman/redis/license)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/workerman/redis.svg)](https://php.net)

Wire-compatible with both Redis and [Dragonfly](https://www.dragonflydb.io/). Supports two execution modes:

- **Callback mode** — works out of the box, no extra dependencies.
- **Coroutine mode** — if [`revolt/event-loop`](https://github.com/revoltphp/event-loop) is installed, methods can be called without a callback and the current fiber suspends until the result arrives.

## Install

```
composer require workerman/redis
```

## Usage

```php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Redis\Client;
use Workerman\Worker;

$worker = new Worker('http://0.0.0.0:6161');

$worker->onWorkerStart = function() {
    global $redis;
    $redis = new Client('redis://127.0.0.1:6379');
};

$worker->onMessage = function($connection, $data) {
    global $redis;
    $redis->set('key', 'hello world');
    $redis->get('key', function ($result) use ($connection) {
        $connection->send($result);
    });
};

Worker::runAll();
```

## Development

```
composer install
composer test          # Pest
composer analyze       # PHPStan
composer test:coverage # Pest with coverage (requires Xdebug or PCOV)
```

Integration tests connect to a real Redis/Dragonfly at `REDIS_URL` (default `redis://127.0.0.1:6379`). Tests skip cleanly when no server is reachable.

## Documentation

http://doc.workerman.net/components/workerman-redis.html
