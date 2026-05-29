# redis

Asynchronous Redis client for PHP, built on Workerman.

[![Latest Stable Version](https://poser.pugx.org/workerman/redis/v/stable)](https://packagist.org/packages/workerman/redis)
[![Total Downloads](https://poser.pugx.org/workerman/redis/downloads)](https://packagist.org/packages/workerman/redis)
[![License](https://poser.pugx.org/workerman/redis/license)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/workerman/redis.svg)](https://php.net)
[![CI](https://github.com/detain/redis/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/redis/actions/workflows/ci.yml)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/1860b96ba19b45b695b7724524f01dfa)](https://app.codacy.com/gh/detain/redis/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![Codacy Coverage](https://app.codacy.com/project/badge/Coverage/1860b96ba19b45b695b7724524f01dfa)](https://app.codacy.com/gh/detain/redis/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_coverage)
[![codecov](https://codecov.io/gh/detain/redis/graph/badge.svg?token=ntRuLnxa2V)](https://codecov.io/gh/detain/redis)  


| ![Sunburst](https://codecov.io/gh/detain/redis/graphs/sunburst.svg?token=ntRuLnxa2V) *Sunburst*   | ![Grid](https://codecov.io/gh/detain/redis/graphs/tree.svg?token=ntRuLnxa2V) *Grid* | ![Icicle](https://codecov.io/gh/detain/redis/graphs/icicle.svg?token=ntRuLnxa2V) *Icicle* |
|------|------|------|
| <sub>The inner-most circle is the entire project, moving away from the center are folders then, finally, a single file. The size and color of each slice is representing the number of statements and the coverage, respectively.</sub> | <sub>Each block represents a single file in the project. The size and color of each block is represented by the number of statements and the coverage, respectively.</sub> | <sub>The top section represents the entire project. Proceeding with folders and finally individual files. The size and color of each slice is representing the number of statements and the coverage, respectively.</sub> |



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

## Pub/Sub

`subscribe()` / `pSubscribe()` / `sSubscribe()` put the connection into
subscribe mode and stream messages to your callback. Once subscribed, the
connection is *locked* — Redis only allows (un)subscribe commands on it — so
the matching `unsubscribe()` / `pUnsubscribe()` / `sUnsubscribe()` methods are
how you hand it back for ordinary commands. They write the teardown frame
straight to the socket (bypassing the lock), and the connection resumes normal
work as soon as the server confirms zero remaining subscriptions.

```php
$redis->subscribe(['news', 'sport'], function ($channel, $message) {
    // fires for every published message
});

// Later — e.g. from a Timer — drop the subscription and resume normal use.
$redis->unsubscribe();                      // omit args to drop every channel
$redis->get('some:key', function ($value) {
    // runs now that the connection is no longer locked
});
```

`unsubscribe()` takes an optional list of channels (omit to drop them all) and
an optional trailing callback that fires with `(true, $client)` once the
connection has fully left subscribe mode. That callback means "back to normal
command mode" — on a partial unsubscribe (dropping some of several channels) it
is held until the last channel goes too, so track per-channel state in your
subscribe callback if you need it. `pUnsubscribe()` mirrors it for
`pSubscribe()` patterns, and `sUnsubscribe()` for `sSubscribe()` shard
channels. Calling them when not subscribed is a no-op that still invokes the
callback. To stop listening entirely you can also just `close()` the client.

## Monitor

`monitor()` streams every command the server processes to your callback — the
debugging counterpart to `redis-cli MONITOR`. Like `subscribe()` it locks the
connection (its own internal flag); there is no "unmonitor", so you stop it by
`close()`ing the client. The opening `+OK` handshake is swallowed; each later
call is one raw monitor line.

```php
$debug = new Client('redis://127.0.0.1:6379');
$debug->monitor(function ($line) {
    // e.g. 1700000000.123456 [0 127.0.0.1:6379] "set" "key" "value"
    error_log($line);
});
```

> **Heads up:** MONITOR mirrors *all* traffic the server handles and measurably
> lowers its throughput. Use a dedicated client for it and never leave one
> running on a hot path.

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

## Coroutine mode

Every command accepts an optional **trailing callback** as shown above. If you
install [`revolt/event-loop`](https://github.com/revoltphp/event-loop), you can
instead **omit the callback** and the current fiber suspends until the reply
arrives, so the call reads synchronously and returns the value directly:

```php
// Callback mode (always available):
$redis->get('key', function ($value) { /* ... */ });

// Coroutine mode (with revolt/event-loop installed):
$value = $redis->get('key');   // suspends this fiber, returns the value
```

This applies to the whole command surface below — including the `scanAll()`
iterators, the module dispatchers, and the server-admin helpers.

## Command reference

The fork exposes a typed `@method` surface for every command
[Dragonfly](https://www.dragonflydb.io/) fully or partially supports, so IDEs
and PHPStan see them. Commands marked **new** were added or fixed in this fork
(see [`CHANGELOG.md`](CHANGELOG.md)); the rest carried over from upstream and now
just have declarations and tests. Commands not listed (e.g. `set`, `get`, `del`,
`hSet`, `zAdd`, `lPush`, the `x*` stream verbs) work exactly as before.

| Family | New / fixed in this fork |
|--------|--------------------------|
| **Strings** | `getDel`, `getEx`, `substr` |
| **Keys** | `copy`, `touch`, `expireTime`, `pExpireTime`, **`scan`/`scanAll`** |
| **Hashes** | `hRandField`, **`hScan`/`hScanAll`**, HEXPIRE family (`hExpire`, `hPersist`, `hExpireAt`, `hTtl`, `hExpireTime`, `hPExpire`, `hPExpireAt`, `hPTtl`, `hPExpireTime`) |
| **Lists** | `lMove`, `lMPop`, `lPos`, `blMove`, `blMPop` |
| **Sets** | `sMIsMember`, `sInterCard`, **`sScan`/`sScanAll`** |
| **Sorted sets** | `zRandMember`, `zMScore`, `zDiff`, `zDiffStore`, `zInter`, `zInterCard`, `zUnion`, `zRangeStore`, `zMPop`, `bzMPop`, `zRevRangeByLex`, `zRemRangeByLex`, `zLexCount`, **`zScan`/`zScanAll`** |
| **Streams** | `xAutoClaim`, `xSetId`, **`xAdd`** (explicit, flattens the field map) |
| **Bitmap** | `bitOp`, `bitPos`, `bitField`, **`bitFieldRo`** |
| **Geo** | `geoSearch`, **`geoRadiusRo`**, **`geoRadiusByMemberRo`** |
| **Scripting** | **`evalRo`**, **`evalShaRo`** |
| **Pub/Sub** | `sPublish`, **`sSubscribe`**, **`unsubscribe`/`pUnsubscribe`/`sUnsubscribe`** |
| **Connection/server** | **`ping`/`info`/`dbSize`/`time`/`flushDb`/`flushAll`/`quit`**, `echo`, `hello` |
| **Server admin** | **`config`/`acl`/`slowLog`/`memory`/`command`/`cluster`** dispatchers; **`lastSave`/`save`/`role`/`bgSave`/`shutdown`/`digest`/`monitor`**; `replicaOf`, `slaveOf`, `debug`, `delEx` |
| **Read-only bridges** | **`sortRo`**, **`rawCommand`** |
| **JSON module** | **`json()`** + 16 `json*` shortcuts |
| **Bloom Filter** | **`bf()`** + `bfReserve`, `bfAdd`, `bfExists`, `bfMAdd`, `bfMExists` |
| **Count-Min Sketch** | **`cms()`** + `cmsInitByDim`, `cmsInitByProb`, `cmsIncrBy`, `cmsQuery`, `cmsMerge`, `cmsInfo` |
| **TopK** | **`topk()`** + `topkReserve`, `topkAdd`, `topkIncrBy`, `topkQuery`, `topkCount`, `topkList`, `topkInfo` |
| **RediSearch (FT)** | **`ft()`** + `ftCreate`, `ftSearch`, `ftAggregate`, `ftDropIndex`, `ftInfo`, `ftList`, `ftAlter`, `ftConfig`, `ftTagVals`, `ftSynDump`, `ftSynUpdate`, `ftProfile` |
| **Modules** | **`module()`**, `moduleList` |

The SCAN family, `rawCommand`, Pub/Sub, `monitor()`, the no-arg server commands,
and the JSON module each have their own section above. The remaining families are
documented below.

## Streams — `xAdd()`

`xAdd()` takes the message as a natural `['field' => 'value']` map and flattens
it onto the wire itself, so field names survive (passing the map through the
generic dispatcher would emit values only and the server would reject it):

```php
$redis->xAdd('mystream', '*', ['sensor' => 'temp', 'value' => '21.5'], function ($id) {
    // $id === e.g. '1700000000000-0'
});

// Cap the stream length (MAXLEN [~] n) — the 4th/5th args, or pass the callback directly.
$redis->xAdd('mystream', '*', ['k' => 'v'], 1000, true, function ($id) { /* ~1000 cap */ });
```

`xAutoClaim()` and `xSetId()` route through `__call()` and take their usual
arguments plus an optional trailing callback.

## Modern list / set / sorted-set commands

These all take a trailing callback (or return a value in coroutine mode):

```php
// Lists
$redis->lMove('src', 'dst', 'LEFT', 'RIGHT', function ($moved) {});
$redis->lPos('mylist', 'needle', ['RANK' => 1, 'COUNT' => 2], function ($positions) {});

// Sets
$redis->sMIsMember('myset', 'a', 'b', 'c', function ($flags) {});       // [1, 0, 1]
$redis->sInterCard(2, ['s1', 's2'], 10, function ($card) {});           // bounded cardinality

// Sorted sets
$redis->zMScore('z', 'm1', 'm2', function ($scores) {});                // ['1', '2'] (strings)
$redis->zRangeStore('dst', 'src', 0, -1, [], function ($count) {});
$redis->zUnion(2, ['z1', 'z2'], ['WITHSCORES'], function ($rows) {});
```

## Bitmap, Geo & read-only scripting

The `*_RO` (read-only) verbs need explicit methods because uppercasing a
camelCase name drops the underscore. Each accepts the callable-as-last-arg
shortcut:

```php
$redis->bitFieldRo('bf', 'GET', 'i5', 0, function ($vals) {});
$redis->geoRadiusRo('geo', 13.4, 52.5, 200, 'km', ['WITHDIST'], function ($rows) {});
$redis->geoRadiusByMemberRo('geo', 'Berlin', 200, 'km', [], function ($rows) {});
$redis->evalRo('return ARGV[1]', ['hello'], 0, function ($r) {});
$redis->evalShaRo($sha, ['hello'], 0, function ($r) {});

// SORT_RO — same option grammar as sort():
$redis->sortRo('mylist', ['ALPHA' => true, 'LIMIT' => [0, 10]], function ($sorted) {});
```

`bitOp`, `bitPos`, `bitField`, and `geoSearch` route cleanly through `__call()`.

## Server administration

Multi-verb admin families are reached through dispatchers — the first argument is
the subcommand, the rest are its arguments, and an optional trailing callable is
the callback:

```php
$redis->config('GET', 'maxmemory', function ($pairs) {});      // CONFIG GET maxmemory
$redis->config('SET', 'maxmemory', '256mb', function ($ok) {});
$redis->acl('WHOAMI', function ($user) {});                    // ACL WHOAMI
$redis->slowLog('GET', 10, function ($entries) {});            // SLOWLOG GET 10
$redis->memory('USAGE', 'mykey', function ($bytes) {});        // MEMORY USAGE mykey
$redis->command('COUNT', function ($n) {});                    // COMMAND COUNT
$redis->cluster('INFO', function ($info) {});                  // CLUSTER INFO

// Lifecycle verbs (explicit — these fix the no-arg-callback bug):
$redis->lastSave(function ($ts) {});
$redis->save(function ($ok) {});
$redis->role(function ($role) {});
$redis->bgSave(false, function ($ok) {});      // pass true for BGSAVE SCHEDULE
// $redis->shutdown('SAVE');                    // closes the connection; no reconnect
```

`shutdown()` sets the same don't-reconnect flag `quit()` uses, so the client
won't silently re-open the socket the server just closed.

## Bloom Filter / Count-Min Sketch / TopK modules

RedisBloom-compatible probabilistic structures — native in Dragonfly. Each
family has a dispatcher (`bf()`, `cms()`, `topk()`) plus typed shortcuts:

```php
// Bloom Filter
$redis->bfReserve('seen', 0.01, 100000, function ($ok) {});
$redis->bfAdd('seen', 'user:42', function ($added) {});        // 1 first time, 0 after
$redis->bfMExists('seen', 'a', 'b', 'c', function ($flags) {}); // [1, 0, 0]

// Count-Min Sketch
$redis->cmsInitByProb('freq', 0.001, 0.01, function ($ok) {});
$redis->cmsIncrBy('freq', 'page:/', 1, function ($counts) {});
$redis->cmsQuery('freq', 'page:/', function ($estimates) {});

// TopK
$redis->topkReserve('top', 10, function ($ok) {});             // width/depth/decay default
$redis->topkAdd('top', 'apple', 'banana', function ($dropped) {});
$redis->topkList('top', function ($leaders) {});
```

> Replies follow Dragonfly's shapes: `BF.ADD`/`BF.EXISTS` return ints (1/0);
> `CMS.INFO`/`TOPK.INFO` come back as flat `[name, value, …]` arrays; `TOPK.COUNT`
> is approximate and may under-count by ~1.

## RediSearch (FT) module

Full-text/secondary-index search — preloaded in Dragonfly. The `ft()` dispatcher
prepends `FT.`; typed shortcuts cover the common verbs:

```php
$redis->ftCreate('idx', 'ON', 'HASH', 'PREFIX', 1, 'doc:', 'SCHEMA', 'title', 'TEXT', function ($ok) {
    $redis->ftSearch('idx', 'hello', function ($results) {
        // [total, 'doc:1', ['title', 'hello world'], ...]
    });
});

$redis->ftInfo('idx', function ($meta) {});
$redis->ftList(function ($indexes) {});           // FT._LIST
$redis->ftDropIndex('idx', true, function ($ok) {}); // true => DD (also delete docs)
```

Anything without a shortcut goes through the dispatcher directly, e.g.
`$redis->ft('AGGREGATE', 'idx', '*', 'GROUPBY', 1, '@title', $cb)`.

## Development

```
composer install
composer test          # Pest
composer analyze       # PHPStan
composer test:coverage # Pest with coverage (requires Xdebug or PCOV)
```

Integration tests connect to a real Redis/Dragonfly at `REDIS_URL` (default `redis://127.0.0.1:6379`). Tests skip cleanly when no server is reachable.

## Testing & continuous integration

The fork ships with a [Pest](https://pestphp.com/) test suite —
**198 tests / 620 assertions, all green against a live Dragonfly** — split into
two tiers, so most of the code can be exercised without a server and the rest is
verified end-to-end against a live engine:

- **Unit suite (`tests/Unit/`) — no server needed.** Pure, mock-style tests that
  run anywhere:
  - `ProtocolTest` round-trips the RESP encoder/decoder directly — nested
    arrays, null bulks/arrays, deep-nesting within `MAX_DEPTH`, the
    depth-overflow protocol-error path, truncated frames, and empty replies.
  - `MethodSurfaceTest` uses reflection to lock in the method surface for
    commands that can't be run live (`shutdown`, `monitor`, the unsubscribe
    family).
- **Feature suite (`tests/Feature/`) — live integration.** Because
  `Worker::runAll()` takes over the process, each integration assertion runs in
  its own short-lived Workerman subprocess via a `runInWorker($snippet)` helper:
  the snippet executes inside a real worker with `$redis`, `$emit()`, and
  `$fail()` in scope and returns its result over a dedicated pipe. Every command
  family — Strings, Keys, Hashes, Lists, Sets, Sorted Sets, the SCAN iterators,
  Streams, Pub/Sub, Bitmap, Geo, scripting, server admin, and the JSON / Bloom /
  CMS / TopK / RediSearch modules — has live round-trip coverage. The suite
  **skips cleanly** when no server is reachable, so `composer test` stays green
  on a bare checkout.

Static analysis runs alongside the tests: **PHPStan** (level 5, with a baseline
that freezes legacy typing issues so new code can't regress).

### GitHub Actions

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every push and
pull request to `master` (and on demand via `workflow_dispatch`):

- **Matrix across PHP 8.1, 8.2, and 8.3.**
- **A live Dragonfly** is brought up as a service container
  (`docker.dragonflydb.io/dragonflydb/dragonfly` on port 6379), so the Feature
  suite runs against the fork's canonical compatibility target on every run —
  not just mocks.
- **PHPStan + Pest** execute on all three legs; the 8.3 leg additionally
  collects line coverage with **PCOV** and uploads a Clover report to
  **[Codecov](https://codecov.io/gh/detain/redis)** and
  **[Codacy](https://app.codacy.com/gh/detain/redis/dashboard)** (a dedicated
  `codacy-coverage-reporter` job consumes the artifact).
- Composer downloads are cached per PHP version to keep runs fast.

Because every new command landed with its own integration test, **coverage of
the code added in this fork runs at nearly 95%** — each dispatcher, explicit
method, and the rewritten RESP decoder is exercised by at least one live test.
Coverage badges at the top of this README reflect the latest `master` run.

## Documentation

http://doc.workerman.net/components/workerman-redis.html
