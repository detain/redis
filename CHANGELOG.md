# Changelog

All notable changes to this fork of [`workerman/redis`](https://github.com/workerman-php/redis)
are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This fork (`detain/redis`) diverged from upstream at the `Update Redis.php`
commit (`49627c1`). Everything below is **new in the fork** — upstream changes
(SSL support, Workerman v5 support, the reconnect/auth-db fix) predate the fork
point and are not repeated here.

The headline of the fork is a **complete, typed, Dragonfly-targeted command
surface**: every command [Dragonfly](https://www.dragonflydb.io/) fully or
partially supports now has an `@method` declaration for IDE/PHPStan, an
integration test, and — wherever the generic `__call()` route was broken — a
real explicit implementation. Both execution modes (callback and Revolt
coroutine) are supported throughout.

---

## [Unreleased] — Dragonfly-complete command surface

### Summary

| Area | What changed |
|------|--------------|
| **Protocol** | RESP decoder rewritten to parse arbitrarily nested arrays (was flat-only); depth-bounded against stack exhaustion. |
| **SCAN family** | `scan`/`hScan`/`sScan`/`zScan` were throwing stubs — now fully implemented, each with a loop-driving `*All()` iterator helper. |
| **Broken `__call()` paths fixed** | No-arg-plus-callback commands (`ping`, `info`, `quit`, …), underscore verbs (`SORT_RO`, `EVAL_RO`, …), dotted module verbs (`JSON.*`, `BF.*`, …), and `rawCommand` all got explicit methods that route correctly. |
| **New command coverage** | ~140 commands documented/implemented across Strings, Keys, Hashes, Lists, Sets, Sorted Sets, Streams, Pub/Sub, Bitmap, Geo, Scripting, Server admin, and the JSON / Bloom / CMS / TopK / RediSearch modules. |
| **Pub/Sub** | Sharded pub/sub (`sPublish`/`sSubscribe`), the full `unsubscribe`/`pUnsubscribe`/`sUnsubscribe` teardown family, and `monitor()` streaming. |
| **Tooling** | Pest test harness (unit + subprocess-based integration), PHPStan with baseline, GitHub Actions CI on PHP 8.1/8.2/8.3 against live Dragonfly, Codecov + Codacy coverage. |
| **Requirements** | PHP bumped from `>=7` to `>=8.1`. |

---

### Added — Commands by family

#### Strings
`getDel`, `getEx`, `substr` documented (`@method` + tests). All routed through
`__call()` already; the declarations expose them to IDE autocomplete and PHPStan.

#### Keys
- `copy`, `touch`, `expireTime`, `pExpireTime` documented.
- **`scan` / `scanAll`** — see *SCAN family* below.

#### Hashes
- `hRandField` documented.
- **`hScan` / `hScanAll`** — see *SCAN family*.
- **HEXPIRE family** documented (`@method` only, via `__call`): `hExpire`,
  `hPersist`, `hExpireAt`, `hTtl`, `hExpireTime`, `hPExpire`, `hPExpireAt`,
  `hPTtl`, `hPExpireTime`. *(Dragonfly: partial — currently supports
  `HEXPIRE`/`HTTL`; the tests accept either a real per-field integer-array reply
  or an `-ERR unknown command`, so they start asserting real values
  automatically as Dragonfly catches up.)*

#### Lists
`lMove`, `lMPop`, `lPos`, `blMove`, `blMPop` documented.

#### Sets
- `sMIsMember`, `sInterCard` documented.
- **`sScan` / `sScanAll`** — see *SCAN family*.

#### Sorted Sets
`zRandMember`, `zMScore`, `zDiff`, `zDiffStore`, `zInter`, `zInterCard`,
`zUnion`, `zRangeStore`, `zMPop`, `bzMPop`, `zRevRangeByLex`, `zRemRangeByLex`,
`zLexCount` documented. Plus **`zScan` / `zScanAll`** (see *SCAN family*).

#### Streams
- `xAutoClaim`, `xSetId` documented.
- **`xAdd()` — new explicit method (encoder fix).** The RESP encoder flattens a
  nested array argument by emitting its *values only*, so a
  `['field' => 'value']` message passed to `XADD` through `__call()` lost the
  field names and the server rejected it. `xAdd($key, $id, $message, $maxLen = 0,
  $approximate = false, $cb = null)` flattens the message itself so the natural
  field-map shape works. Signature mirrors phpredis; `MAXLEN [~] n` is emitted
  before the id; an empty message throws `InvalidArgumentException`.

#### Bitmap
- `bitOp`, `bitPos`, `bitField` documented (route cleanly through `__call`).
- **`bitFieldRo`** — explicit underscore-bridge method (wire verb
  `BITFIELD_RO`; `__call`'s `strtoupper` would have produced `BITFIELDRO`).

#### Geo
- `geoSearch` documented.
- **`geoRadiusRo`, `geoRadiusByMemberRo`** — explicit underscore-bridge methods
  (`GEORADIUS_RO`, `GEORADIUSBYMEMBER_RO`).

#### Scripting
- **`evalRo`, `evalShaRo`** — explicit underscore-bridge methods (`EVAL_RO`,
  `EVALSHA_RO`).

#### Pub/Sub
- **`sPublish`** documented; **`sSubscribe()`** explicit (mirrors
  `pSubscribe()`; `process()` now flips the subscribe-lock on `SSUBSCRIBE` too).
- **`unsubscribe()` / `pUnsubscribe()` / `sUnsubscribe()`** — explicit, with a
  lock bypass. A subscribed connection refuses queued commands, so these write
  the teardown frame straight to the socket via `$_connection->send()`, then a
  new `handleUnsubscribeAck()` clears `$_subscribe`, drops the stale `SUBSCRIBE`
  entry, and drains anything queued while locked. The optional trailing callback
  fires `(true, $client)` once the connection is fully back in normal mode (held
  until the *last* channel drops on a partial unsubscribe). Calling when not
  subscribed is a no-op that still invokes the callback.

#### Connection / server
- **`ping`, `info`, `dbSize`, `time`, `flushDb`, `flushAll`** — explicit, fixes
  the no-arg-plus-callback bug (see *Fixed*). `flushDb`/`flushAll` take an
  optional `ASYNC` boolean.
- **`quit`** — explicit, with don't-reconnect semantics (a new `$_quitting` flag
  the `onClose` handler honours, skipping the 5s reconnect timer).
- `echo`, `hello` documented; `hello()` is explicit so `hello($cb)` folds the
  closure into the `$cb` slot.

#### Server administration
- **Subcommand dispatchers** (thin wrappers over `dispatcher()`):
  `config()`, `acl()`, `slowLog()`, `memory()`, `command()`, `cluster()`.
- **Explicit lifecycle verbs**: `lastSave()`, `save()`, `role()`,
  `bgSave($schedule = false, …)`, `digest()` *(Dragonfly extension)*,
  `shutdown($mode = 'SAVE', …)` *(sets `$_quitting` so the socket teardown
  doesn't trigger a reconnect)*.
- **`monitor($cb)`** — streams every command the server processes. Long-lived
  like `subscribe()`, but with its own `$_monitoring` lock (there is no
  `UNMONITOR`; stop it by `close()`ing the client). The opening `+OK` handshake
  is swallowed; each later call is one raw monitor line. A re-entry guard ignores
  `monitor()` on an already-streaming connection.
- `replicaOf`, `slaveOf`, `debug`, `delEx` *(Dragonfly extension)* documented.

#### JSON module (RedisJSON-compatible, native in Dragonfly)
- **`json(...$args)`** dispatcher (`JSON.` prefix).
- **16 typed shortcuts**: `jsonSet`, `jsonMSet`, `jsonMerge`, `jsonGet`,
  `jsonMGet`, `jsonType`, `jsonObjKeys`, `jsonObjLen`, `jsonArrLen`,
  `jsonStrLen`, `jsonDel`, `jsonForget`, `jsonArrAppend`, `jsonNumIncrBy`,
  `jsonStrAppend`, `jsonToggle`.

#### Bloom Filter / Count-Min Sketch / TopK modules (RedisBloom-compatible)
- **Dispatchers**: `bf()` (`BF.`), `cms()` (`CMS.`), `topk()` (`TOPK.`).
- **Bloom Filter (5)**: `bfReserve`, `bfAdd`, `bfExists`, `bfMAdd`, `bfMExists`.
- **Count-Min Sketch (6)**: `cmsInitByDim`, `cmsInitByProb`, `cmsIncrBy`,
  `cmsQuery`, `cmsMerge` (optional `WEIGHTS` clause), `cmsInfo`.
- **TopK (7)**: `topkReserve`, `topkAdd`, `topkIncrBy`, `topkQuery`,
  `topkCount`, `topkList`, `topkInfo`.

#### RediSearch / FT module (preloaded in Dragonfly)
- **`ft(...$args)`** dispatcher (`FT.` prefix) + **11 typed shortcuts**:
  `ftCreate`, `ftSearch`, `ftAggregate`, `ftDropIndex` (optional `DD`),
  `ftInfo`, `ftList` (`FT._LIST`), `ftAlter`, `ftConfig`, `ftTagVals`,
  `ftSynDump`, `ftSynUpdate`, `ftProfile`.

#### Modules introspection
- **`module(...$args)`** dispatcher + **`moduleList()`** (`MODULE LIST`).
  `MODULE LOAD` is wired but docs-only — Dragonfly's modules are static.

#### Read-only / underscore-verb bridges
- **`sortRo()`** — explicit, emits `SORT_RO` (matches the `bitFieldRo` /
  `geoRadiusRo` / `evalRo` pattern). Mirrors `sort()`'s option grammar.
- **`rawCommand(...$args)`** — explicit escape hatch (see *Fixed*).

### Added — SCAN family (was throwing stubs)

`scan`, `hScan`, `sScan`, `zScan` previously `throw new Exception('Not
implemented')`. All four are now real, each with a loop-driving `*All()`
iterator helper that supports both callback and Revolt coroutine modes:

| Single-call | Iterator | Reply reshaped to | Notes |
|-------------|----------|-------------------|-------|
| `scan($cursor, $opts, $cb)` | `scanAll($opts, $cb)` | `['cursor' => …, 'keys' => […]]` | may yield duplicate key *names* across the keyspace (documented caller responsibility) |
| `hScan($key, $cursor, $opts, $cb)` | `hScanAll($key, $opts, $cb)` | `['cursor' => …, 'fields' => assoc]` | duplicate fields overwrite (unique by definition) |
| `sScan($key, $cursor, $opts, $cb)` | `sScanAll($key, $opts, $cb)` | `['cursor' => …, 'members' => […]]` | `sScanAll` dedupes via a string-keyed map (defeats PHP numeric-string coercion) |
| `zScan($key, $cursor, $opts, $cb)` | `zScanAll($key, $opts, $cb)` | `['cursor' => …, 'members' => member=>score]` | **scores kept as raw strings** to preserve precision |

- Options (`MATCH`, `COUNT`, `TYPE` for `scan`) are **case-insensitive**;
  unknown keys are silently ignored.
- `*All()` accepts a `'limit'` option (default `100000`) so a growing keyspace
  can't loop forever.
- On a Redis-side error the callback receives `false` (matches the client's
  error convention).

### Added — Tooling & infrastructure

- **Pest test harness** with separate Unit and Feature suites.
  - Unit: `ProtocolTest` (RESP encode/decode round-trips, no server needed),
    `MethodSurfaceTest` (reflection guards for methods that can't run live —
    `shutdown`, `monitor`, the unsubscribe family).
  - Feature: a **subprocess-based integration harness** — `runInWorker($snippet)`
    `proc_open`s a short-lived PHP child running the snippet inside a Workerman
    worker with `$redis`, `$emit($value)`, `$fail($msg)` in scope, returning the
    result over fd 3 (stdout carries Workerman's boot banner). Tests skip
    cleanly when no Redis/Dragonfly is reachable at `REDIS_URL`
    (default `redis://127.0.0.1:6379`). **198 tests / 620 assertions**, all
    passing against a live Dragonfly.
- **PHPStan** at level 5 with a baseline (`phpstan-baseline.neon`) snapshotting
  pre-existing legacy typing issues so new commits can't regress past that line.
  The baseline shrank from 44 → 9 entries as the refactors fixed typing nits.
- **GitHub Actions CI** (`.github/workflows/ci.yml`): Pest + PHPStan on PHP 8.1,
  8.2, 8.3 against a live Dragonfly (installed via APT / Docker image), with
  Composer caching, Codecov upload (8.3 leg only), and a separate Codacy
  coverage-reporter job.
- **`composer.json`**: description/keywords/authors filled in;
  `require-dev` (Pest, Mockery, PHPStan); `suggest` revolt/event-loop;
  `Tests\\` autoload-dev; `analyze` / `test` / `test:coverage` scripts.
- **README**: badges (CI, Codecov, Codacy grade + coverage, Packagist, license,
  PHP version), coverage-graph visualizations, and usage sections for every new
  surface.

### Fixed

- **Nested-array RESP replies.** The decoder (`src/Protocols/Redis.php`) was
  flat-only and could not parse multi-bulk replies like SCAN's
  `[cursor, [keys]]`. Rewritten into recursive `measure()` / `decodeOne()`
  helpers that walk any RESP type at any depth, bounded by `MAX_DEPTH = 64`
  (deeper replies surface as a protocol error rather than blowing PHP's stack).
  Null bulks (`$-1`) and null arrays (`*-1`) now detect via
  `$offset === strpos(...)` instead of `0 === strpos(...)`, so they decode
  correctly when *nested* (the old form only matched at buffer offset 0,
  breaking nested nils inside MGET-style replies). All flat-reply contracts
  preserved.
- **No-arg-plus-callback commands silently broke.** `__call()` only extracts a
  trailing callable when `count($args) > 1` (or for a tiny allowlist), so
  `$redis->ping($cb)` shipped the closure to the server as a `PING` argument.
  Fixed for `ping`, `info`, `dbSize`, `time`, `flushDb`, `flushAll`, `quit`,
  `hello`, `lastSave`, `save`, `role`, `bgSave`, `digest`, `shutdown`, `monitor`
  via explicit methods (rather than touching `__call()`, where
  `is_callable('phpinfo')`-style false positives would corrupt single-string
  commands).
- **`rawCommand` always failed.** It was `@method`-declared but unbacked, so it
  fell through `__call()`, which uppercased the method *name* and prepended it —
  `$redis->rawCommand('GET', 'k')` went on the wire as `['RAWCOMMAND','GET','k']`
  and every server returned `-ERR unknown command 'RAWCOMMAND'`. Now an explicit
  method forwards args verbatim and pops a trailing callable as the callback;
  throws `InvalidArgumentException` when no command parts remain.
- **Underscore-verb commands unreachable via `__call()`.** `strtoupper` on a
  camelCase name drops the underscore (`bitFieldRo` → `BITFIELDRO`). Bridged
  with explicit methods: `bitFieldRo`, `geoRadiusRo`, `geoRadiusByMemberRo`,
  `evalRo`, `evalShaRo`, `sortRo`.
- **Dotted module verbs uncallable in PHP.** `JSON.SET`, `BF.ADD`, etc. can't be
  method names. Solved with the `json()`/`bf()`/`cms()`/`topk()`/`ft()`
  dispatchers and typed shortcuts.
- **`xAdd` field names dropped** — see *Streams* above.
- **Wait-timeout timer permanently deleted while streaming.** The constructor's
  timeout timer used to delete itself during a subscribe/monitor stream, so
  after the stream ended (a monitor rejection, an unsubscribe) queued commands
  lost their timeout guard for the life of the connection. It now *skips* while
  streaming and resumes afterward.

#### Async hardening (full-source review pass)

- **Wait-timeout scan leaked its timer — the client could never be GC'd.** The
  constructor's `Timer::add(1, …)` handle was never stored, so `close()` could
  not delete it: the timer kept firing forever, and because its closure captures
  `$this` the client object stayed pinned in memory (defeating the
  `gc_collect_cycles()` in `close()`). In a worker that creates clients
  dynamically this leaked one object + one timer per client. The handle is now
  kept in the (previously unused) `$_waitTimeoutTimer` property and torn down in
  `close()`.
- **Coroutine command on a subscribe/monitor-locked connection hung the fiber.**
  In Revolt mode `queueCommand()` suspends the current fiber until the reply
  arrives — but `process()` refuses to send anything while the connection is in
  subscribe/monitor mode, so the reply (and the resume) could never come while
  the lock held. That was a silent, unrecoverable hang. It now throws a clear
  `Workerman\Redis\Exception` instead of suspending.
- **A second `subscribe()` / `pSubscribe()` / `sSubscribe()` was silently
  dropped.** This client pins one stream entry at the head of the queue and
  routes every message to that entry's callback; a second subscribe while one is
  active or pending can't reach the wire (the lock) and its messages would go to
  the first callback anyway. It now throws rather than failing silently. The
  guard inspects both the live flags **and** the queue, so it also catches
  back-to-back calls issued before the first frame is sent (when the flags are
  still false). `monitor()` keeps its documented silent-ignore contract but
  reuses the same active-or-pending detection.
- **`select()` / `auth()` cached state on a *failed* reply.** Their `format`
  callbacks ran regardless of success, so a rejected `SELECT`/`AUTH` still
  updated `$_db`/`$_auth` — which the next reconnect would then replay. They now
  mutate only when the reply was not an error.
- **Wait-timeout false positives around blocking commands.** The scan only
  exempted `BLPOP`/`BRPOP`, so a long-blocking `BRPOPLPUSH`/`BLMOVE`/`BLMPOP`/
  `BZPOPMIN`/`BZPOPMAX`/`BZMPOP` at the head could trip a reconnect, and commands
  queued *behind* any blocker were failed with a spurious "Wait Timeout" despite
  never having been sent. A `BLOCKING_COMMANDS` set now covers the full family,
  and when the head is a blocking command the scan returns early — nothing behind
  it is timed out.
- **Callback exceptions caught `\Exception`, not `\Throwable`.** An `\Error`
  (e.g. `TypeError`) thrown from a user callback escaped `onMessage()` before
  `process()` could pump the next command, wedging the queue. Widened to
  `catch (\Throwable …)` (still re-thrown after the pump runs).

### Changed

- **Requirement: PHP `>=7` → `>=8.1`** (Pest 3+/4 needs it).
- **Internal refactor — `queueCommand()` + `dispatcher()` helpers.** Every
  explicit command method previously repeated the same ~10-line block
  (Revolt-suspension check, queue push, `process()`, conditional `suspend()`).
  `queueCommand(array $args, $cb, $format)` collapses it to a single
  `return` per method, and `__call()` routes through it too — so the
  callback-vs-coroutine decision lives in exactly one place.
  `dispatcher(string $prefix, array $args)` is the multi-verb / dotted-module
  counterpart (`'CLUSTER '` → `['CLUSTER','INFO',…]`; `'JSON.'` →
  `['JSON.SET',…]`). Net −76 lines despite adding both helpers.

### Known limitations / partial support

These are documented and dispatched, but Dragonfly may not implement every
option (the PHPDoc and tests note it): `SORT_RO`, `COPY`, the `HEXPIRE` family,
`CLIENT KILL` / `CLIENT TRACKING`, `BGSAVE`, several `ACL` write verbs,
`MODULE LOAD`, `BF.RESERVE`, and the `FT.*` search surface across the board.

Commands Dragonfly does **not** support are intentionally **not** added (e.g.
`LCS`, `MIGRATE`, `OBJECT ENCODING`, `WAIT`, `FUNCTION`/`FCALL`, `SWAPDB`,
Cuckoo Filter, Graph, TimeSeries, T-Digest, cluster write ops). See
`async_plan.md` for the full inclusion/exclusion rationale.

---

## Upstream baseline

Everything prior to fork point `49627c1` is upstream `workerman/redis`
(latest tag `v2.0.5`), including SSL support, Workerman v5 support, and the
post-reconnect auth-db fix. See the upstream repository for its history.
