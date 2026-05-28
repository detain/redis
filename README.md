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

| ![Sunburst](https://codecov.io/gh/detain/redis/graphs/sunburst.svg?token=ntRuLnxa2V) | ![Grid](https://codecov.io/gh/detain/redis/graphs/tree.svg?token=ntRuLnxa2V) | ![Icicle](https://codecov.io/gh/detain/redis/graphs/icicle.svg?token=ntRuLnxa2V) |
|------|------|------|
| *Sunburst* <sub>The inner-most circle is the entire project, moving away from the center are folders then, finally, a single file. The size and color of each slice is representing the number of statements and the coverage, respectively.</sub> | *Grid* <sub>Each block represents a single file in the project. The size and color of each block is represented by the number of statements and the coverage, respectively.</sub> | *Icicle* <sub>The top section represents the entire project. Proceeding with folders and finally individual files. The size and color of each slice is representing the number of statements and the coverage, respectively.</sub> |



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

## SSCAN

Non-blocking iterator over a single set's members. `sScan()` wraps a single `SSCAN` call;
`sScanAll()` drives the cursor loop and returns every member as a flat array.

```php
// One step — pass the cursor through yourself.
$redis->sScan('user:42:tags', '0', ['MATCH' => 'topic_*', 'COUNT' => 100], function ($reply) {
    // $reply === ['cursor' => '17', 'members' => ['topic_php', 'topic_redis', ...]]
});

// Iterator helper — collects every member of the set.
$redis->sScanAll('user:42:tags', ['COUNT' => 200], function ($members) {
    foreach ($members as $member) {
        // ...
    }
});
```

The `limit` option (default `100000`) caps the total members collected by `sScanAll()`.
On a Redis-side error the callback receives `false`.

## ZSCAN

Non-blocking iterator over a single sorted set's member=>score map. `zScan()` wraps a single
`ZSCAN` call; `zScanAll()` drives the cursor loop and returns every member=>score pair as an
associative array. Scores stay as the raw bulk strings Redis sent — casting to float would
lose precision on values that don't have an exact binary representation.

```php
// One step — pass the cursor through yourself.
$redis->zScan('leaderboard:weekly', '0', ['MATCH' => 'user:*', 'COUNT' => 100], function ($reply) {
    // $reply === ['cursor' => '17', 'members' => ['user:42' => '1500', 'user:7' => '980', ...]]
});

// Iterator helper — collects every member=>score pair for the sorted set.
$redis->zScanAll('leaderboard:weekly', ['COUNT' => 200], function ($members) {
    foreach ($members as $member => $score) {
        // $score is the raw string Redis returned — keep it that way for precision.
    }
});
```

The `limit` option (default `100000`) caps the total members collected by `zScanAll()`.
On a Redis-side error the callback receives `false`.

## rawCommand

Escape hatch for sending any Redis or Dragonfly command verbatim — useful for verbs that don't yet have a dedicated wrapper (new server commands, custom modules, multi-word admin verbs, etc.). The args you pass are the wire payload: the first non-callback arg is the command name and the rest are its arguments. The optional trailing callable receives the reply.

```php
$redis->rawCommand('CONFIG', 'GET', 'maxmemory', function ($reply) {
    // $reply === ['maxmemory', '0']
});
```

Calling `rawCommand()` with no args (or only a callback) throws `InvalidArgumentException` rather than sending an empty command.

## Server commands

Explicit wrappers for the no-arg health and admin commands: `ping()`, `info()`, `dbSize()`, `time()`, `flushDb()`, `flushAll()`. These bypass `__call()`'s trailing-callback handling (which only triggers when more than one argument is passed), so the closure goes through `queueCommand()` instead of being shipped to Redis as a bogus command arg.

```php
$redis->ping(function ($reply) {
    // $reply === 'PONG'
});

$redis->dbSize(function ($count) {
    // $count is an int — number of keys in the current DB
});
```

`info($section, $cb)` accepts an optional section filter (`'server'`, `'memory'`, `'clients'`, …). `flushDb($async, $cb)` and `flushAll($async, $cb)` take an optional first arg — pass `true` to send `FLUSHDB ASYNC` / `FLUSHALL ASYNC` for a non-blocking flush, or pass the callback directly for a synchronous one.

## JSON module

The `json()` dispatcher and `jsonSet()` / `jsonGet()` / `jsonDel()` / `jsonMerge()` / `jsonArrAppend()` / … shortcuts speak the RedisJSON `JSON.*` command family. Dragonfly implements this natively (no module install needed); on stock Redis the same wire form works against a server with RedisJSON loaded. Values cross the wire as JSON-encoded strings — the client does not auto-decode replies, so use `json_decode($reply, true)` where you need a PHP array.

```php
$redis->jsonSet('user:42', '$', '{"name":"alice","tags":["a","b"]}', function ($ok) use ($redis) {
    $redis->jsonGet('user:42', function ($reply) {
        $doc = json_decode($reply, true);
        // $doc === ['name' => 'alice', 'tags' => ['a', 'b']]
    });
});
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
