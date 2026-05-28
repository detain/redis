# redis

Asynchronous Redis client for PHP, built on Workerman.

[![CI](https://github.com/detain/redis/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/redis/actions/workflows/ci.yml)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/detain/redis)](https://app.codacy.com/gh/detain/redis/dashboard)
[![Codacy Coverage](https://app.codacy.com/project/badge/Coverage/detain/redis)](https://app.codacy.com/gh/detain/redis/dashboard)
[![Latest Stable Version](https://poser.pugx.org/workerman/redis/v/stable)](https://packagist.org/packages/workerman/redis)
[![Total Downloads](https://poser.pugx.org/workerman/redis/downloads)](https://packagist.org/packages/workerman/redis)
[![License](https://poser.pugx.org/workerman/redis/license)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/workerman/redis.svg)](https://php.net)

[![codecov](https://codecov.io/gh/detain/redis/graph/badge.svg?token=ntRuLnxa2V)](https://codecov.io/gh/detain/redis)  
![Sunburst](https://codecov.io/gh/detain/redis/graphs/sunburst.svg?token=ntRuLnxa2V)<sub><sub>The inner-most circle is the entire project, moving away from the center are folders then, finally, a single file. The size and color of each slice is representing the number of statements and the coverage, respectively.</sub></sub>  
![Grid](https://codecov.io/gh/detain/redis/graphs/tree.svg?token=ntRuLnxa2V) <sub><sub>Each block represents a single file in the project. The size and color of each block is represented by the number of statements and the coverage, respectively.</sub></sub> 
![Icicle](https://codecov.io/gh/detain/redis/graphs/icicle.svg?token=ntRuLnxa2V) <sub><sub>The top section represents the entire project. Proceeding with folders and finally individual files. The size and color of each slice is representing the number of statements and the coverage, respectively.</sub></sub>



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

## SCAN

Non-blocking alternative to `KEYS *`. `scan()` wraps a single `SCAN` call;
`scanAll()` drives the cursor loop and returns every matching key.

Both examples assume `$redis` is a connected `Client` (see the `Usage` block above).

```php
// One step — pass the cursor through yourself.
$redis->scan('0', ['MATCH' => 'user:*', 'COUNT' => 100], function ($reply) {
    // $reply === ['cursor' => '17', 'keys' => ['user:1', 'user:5', ...]]
});

// Iterator helper — collects every matching key.
$redis->scanAll(['MATCH' => 'session:*', 'COUNT' => 200], function ($keys) {
    foreach ($keys as $key) {
        // ...
    }
});
```

The `limit` option (default `100000`) caps the total keys collected by `scanAll()` so a growing keyspace can't loop forever.
On a Redis-side error the callback receives `false`.

## HSCAN

Non-blocking iterator over a single hash's fields. `hScan()` wraps a single `HSCAN` call;
`hScanAll()` drives the cursor loop and returns every field=>value pair as an associative array.

```php
// One step — pass the cursor through yourself.
$redis->hScan('user:42:meta', '0', ['MATCH' => 'pref_*', 'COUNT' => 100], function ($reply) {
    // $reply === ['cursor' => '17', 'fields' => ['pref_theme' => 'dark', ...]]
});

// Iterator helper — collects every field=>value pair for the hash.
$redis->hScanAll('user:42:meta', ['COUNT' => 200], function ($fields) {
    foreach ($fields as $field => $value) {
        // ...
    }
});
```

The `limit` option (default `100000`) caps the total fields collected by `hScanAll()`.
On a Redis-side error the callback receives `false`.

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
