# Test & Coverage Build-Out Plan — `workerman/redis` (detain fork)

> **Goal:** reach **~95% line/method coverage** of `src/` (lower is acceptable
> where genuinely impractical), and run the **entire suite against BOTH Dragonfly
> and a real Redis server** on every CI run and locally, so we always know the
> client works on both engines.
>
> This document is the **coverage gap analysis** + the **execution playbook**.
> Work is split into **Groups → Steps**. Each Step is executed by a dedicated
> sub-agent under a fixed review → fix → re-review loop, then a doc-updater pass
> per group (see §6 *Execution Protocol*).

---

## 1. Current State (verified, not assumed)

> **Note (Group 0 shipped):** the numbers in this section describe the state
> *before* Group 0. Group 0 landed the subprocess coverage merge, so the
> `Client.php` 0.0% / total 7.6% figures below are now historical — the real
> merged baseline is **total ~68.6%** (715/1042), `Client.php` **~66.5%**
> (632/950), `Protocols/Redis.php` **~90.2%**. The suite also now runs on **both**
> Dragonfly (201 passed / 0 skipped) and Redis (196 passed / 5 skipped), and a
> coverage floor gate (min 65, ratcheting toward 95) is enforced in CI. See the
> README *Testing & continuous integration* section for the user-facing writeup.
>
> **Final state (Groups 0–9 complete):** merged total **92.99%** (969/1042),
> `Client.php` **92.32%** (877/950), `Protocols/Redis.php` and `Exception.php`
> **100%**; suite Dragonfly **406 passed / 3 skipped** and Redis **409 passed /
> 0 skipped** (Redis leg skip-free); coverage floor now **90**. The Group-0-era
> figures above are an interim baseline — see *Coverage close-out (Group 9)* at
> the end of this document for the final numbers and the documented residual.

| Source file | Lines | Real coverage today | Notes |
|---|---|---|---|
| `src/Client.php` | 3,611 | **0.0%** *(measurement artifact)* | The whole command surface + dual-mode (callback / Revolt coroutine) engine. |
| `src/Protocols/Redis.php` | 218 | **84.8%** | RESP encode/decode (static). Genuinely exercised by `ProtocolTest`. |
| `src/Exception.php` | 18 | 100% | Trivial. |
| **Total reported** | | **7.6%** | Misleading — see below. |

**Test suite:** 198 tests / 620 assertions, **all green** against live Dragonfly.
- **Unit tier** (`tests/Unit/`, binds `Tests\TestCase`, no server): `ProtocolTest` (17 cases, RESP round-trips + MAX_DEPTH boundary), `MethodSurfaceTest` (5 cases, reflection guards).
- **Feature tier** (`tests/Feature/`, binds `Tests\RedisTestCase`, 22 files): every assertion runs inside a **Workerman event-loop subprocess** via the free function `runInWorker($snippet)` → `proc_open(php tests/Support/run-in-worker.php)`, result returned on fd 3. Covers SCAN family, JSON, Bloom, CMS, TopK, FT.SEARCH, HEXPIRE, bitmap/geo/eval-RO, monitor, pub/sub, unsubscribe family, server/admin, modern list/set/zset/stream verbs, rawCommand, quit.

**CI** (`.github/workflows/ci.yml`, the *only* workflow): matrix `php: [8.1, 8.2, 8.3]`; **Dragonfly service container** on `6379`; `REDIS_URL=redis://127.0.0.1:6379`; PHPStan (`composer analyze`) + Pest; coverage (pcov) only on 8.3 → clover → Codecov + Codacy. Local dev: Dragonfly installed via APT (per project memory), currently serving on 6379.

**Connection config:** everything reads `getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379'` (in `TestCase::redisUrl()`, `runInWorker()`, and `run-in-worker.php`). There is **no `REDIS_HOST`/`REDIS_PORT`** split and **no backend-conditional skip** — a few tests assert "succeeds OR surfaces the wire error" to tolerate engine differences.

---

## 2. The Core Problem (read this before anything else)

**`Client.php`'s 0.0% is an instrumentation artifact, not a real gap.** Feature
tests `eval()` the client code inside a *child* PHP process (`run-in-worker.php`).
pcov runs in the *parent* PHPUnit process and never instruments the child, so none
of the 3,611 lines register — even though they execute on every Feature test.

**Consequence:** *No amount of new Feature tests will move the coverage number*
until coverage is collected inside the worker and merged back. Therefore the very
first work item is fixing measurement. Two complementary tracks:

- **Track A — Subprocess coverage merge (primary).** Make `run-in-worker.php`
  record coverage (pcov/Xdebug) around its `eval()`, write a per-run `.cov`
  (serialized `SebastianBergmann\CodeCoverage` object), and have a parent-side
  collector merge all `.cov` files into the clover report. This surfaces the
  *real* end-to-end coverage of `Client.php` — likely already high given 198
  integration tests, instantly turning the 0% into a true number.
- **Track B — In-process unit tests (secondary, fills logic gaps).** Add Unit-tier
  tests that drive `Client`'s pure logic without the event loop (reflection +
  a fake/stub connection), covering branches integration tests can't easily hit
  (arg-popping edge cases, error formatting, dedup math, the Revolt branch).

Also note: **the Revolt/coroutine return-value branch of `queueCommand()` is
entirely untested** — the worker only runs callback mode.

---

## 3. Coverage Gap Analysis (what needs testing)

### 3A. Measurement gaps (Track A — must fix first)
- Subprocess coverage not collected → `Client.php` invisible. (Group 0.)
- `composer test:coverage` enforces `--min=70` but would fail at 7.6%; CI side-steps
  it by calling pest directly without `--min`. After the merge fix, wire a real floor.

### 3B. `src/Protocols/Redis.php` — 84.8%, push to ~100% (in-process, easy wins)
Uncovered lines (from the pcov run): **36, 62, 65, 70, 76, 79, 89, 100–102, 146,
168, 173, 205, 215**. These are:
- `input()` / `measure()` **frame-length probe** path (partial frame → returns 0,
  "need more bytes"), including nested-array length accumulation.
- `decode()`/`decodeOne()` **error & incomplete branches**: protocol-error tuple
  `['!', <bin2hex dump>]`, `$-1` null bulk, `*-1` null array, empty `*0`,
  truncated frame mid-bulk, MAX_DEPTH overflow message.
Each is a pure static call → cheap Unit test. ~10–14 new cases. (Group 1.)

### 3C. `src/Client.php` — branch/edge gaps (Track B, in-process where possible)
Pure-logic methods reachable without the event loop (reflection / stubbed conn):
- `__call()` trailing-callable popping: the `count($args) > 1` rule and the
  `['randomKey','multi','exec','discard']` exception list (the documented footgun).
- `dispatcher()`: dot-prefix (`JSON.`) glue vs space-prefix (`CLUSTER `) split;
  trailing-callable pop.
- `encode()` already mostly covered via ProtocolTest, but Client-level command
  assembly + one-level array flatten for `MGET`/`HMSET`-style calls.
- `rawCommand()` empty-args → throws `\InvalidArgumentException` (note: **not**
  `Workerman\Redis\Exception`).
- The four `*ScanAll` aggregation helpers (`scanAll`/`hScanAll`/`sScanAll`/
  `zScanAll`): cursor-loop termination, result accumulation/dedup, score-as-string
  precision for `zScanAll`.
- `handleUnsubscribeAck()` lock-clearing bookkeeping.
- `error()` formatting; `suspenstion()` builder (Revolt present vs absent).
- `select()`/`auth()` argument shaping.

End-to-end paths best covered by Feature tests (already partly covered; verify both
backends): pub/sub delivery, monitor stream, reconnect-after-quit, the SCAN cursor
loop against real data, module commands (JSON/BF/CMS/TopK/FT), HEXPIRE field TTLs.

### 3D. Dual-mode gap
- **Revolt coroutine mode**: `queueCommand()`’s `!$cb && class_exists(EventLoop::class)`
  branch + `suspenstion()` + `onMessage` resume. Requires `revolt/event-loop`
  (already a `suggest`) and a Revolt-driven worker variant. (Group 8.)

### 3E. Command-surface completeness (lower priority — already broad)
198 tests already span 9 "tiers". Cross-check the 290 `@method` declarations against
tested verbs; add the handful with no assertion. Track engine divergences explicitly
rather than silently (see §4). (Group 7.)

---

## 4. Dual-Backend Testing (Dragonfly + Redis)

**Objective:** run the *same* suite against both engines, every CI run and locally.

Design decisions:
- Tests read a single `REDIS_URL`. Keep that contract; vary it per backend.
- **Recommended: CI matrix `backend: [dragonfly, redis]` × existing `php` matrix.**
  Each leg starts only its own service and sets `REDIS_URL` accordingly. Clear
  per-engine red/green; clean isolation.
- Locally, run two servers on different ports (Dragonfly `6379`, Redis `6380`) and
  expose `make test-dragonfly` / `make test-redis` / `make test-all`.
- Add a small **backend-aware skip helper** (free function in `tests/Pest.php`,
  e.g. `currentBackend()` reading a `REDIS_BACKEND` env, + `skipOnBackend('redis', 'reason')`)
  so a legitimately engine-specific case is skipped *with a logged reason*, never
  silently. Record every divergence in README "Compatibility".
- Coverage is engine-agnostic; collect/upload from **one** leg (8.3 + dragonfly) to
  avoid double counting (Group 0.4). **The Redis leg uses `redis/redis-stack-server`**
  so module commands (JSON/Bloom/CMS/TopK/FT) run on both engines.

Pieces to build (Group 0):
- Add a `redis` service container to `ci.yml`; introduce the `backend` matrix axis
  and per-leg `REDIS_URL`/`REDIS_BACKEND`.
- Local Redis provisioning: a committed `scripts/start-redis.sh` (+ document the
  Dragonfly APT recipe already in project memory) and a `Makefile`.
- `phpunit.xml.dist`: keep `REDIS_URL` default; optionally add `REDIS_BACKEND`.

---

## 5. Groups & Steps

Execute groups in order. Within a group, steps are sequential unless marked
*(parallel-safe)*. Every step ends with the §6 loop.

### Group 0 — Coverage measurement + dual-backend (FOUNDATION — do first)
- **0.1** *Subprocess coverage merge (Track A).* Make `run-in-worker.php` collect
  coverage around its `eval()` (pcov via `\pcov\start()/\pcov\collect()`, or a
  `SebastianBergmann\CodeCoverage` instance) and write a unique per-invocation
  `.cov` to a temp dir when a `COLLECT_COVERAGE`/`COVERAGE_DIR` env is set. Add a
  parent-side collector (Pest `afterAll`/a small bin script) that merges all `.cov`
  files and emits `coverage.xml` (clover) + an HTML/text summary. Verify
  `Client.php` jumps from 0% to its true value.
- **0.2** *Add Redis to CI.* In `ci.yml` add a `redis:7` service and a
  `strategy.matrix.backend: [dragonfly, redis]` axis; set `REDIS_URL` +
  `REDIS_BACKEND` per leg. Keep coverage collection on the `php=8.3 && backend=dragonfly`
  leg only.
- **0.3** *Local dual-backend.* Add `scripts/start-redis.sh` (redis-server on 6380),
  a `Makefile` (`test-dragonfly`, `test-redis`, `test-all`, `coverage`), and the
  `currentBackend()`/`skipOnBackend()` helpers in `tests/Pest.php` (free functions —
  honor the project's no-`@var $this`-in-Pest-closures rule).
- **0.4** *Coverage floor (DECIDED: enforce).* Once merge works, wire a real `--min`
  into CI — start at the measured merged baseline and ratchet upward each group;
  CI must fail below the floor. Make `composer test:coverage` the canonical gate
  (replace its `--min=70` with the live floor) and call it from CI on the
  coverage-collecting leg. Align `codecov.yml`/Codacy; single-leg coverage upload
  (php=8.3 && backend=dragonfly).
- **0.5** *Verify baseline:* `make test-all` green on BOTH engines; capture the
  real coverage number as the starting point for the 95% target.

### Group 1 — `Protocols/Redis.php` to ~100% (in-process, fast, no server)
- **1.1** `decode()` error/edge branches: protocol-error `['!', …]` tuple, `$-1`
  null bulk, `*-1` null array, empty `*0`, empty-string element, truncated mid-bulk.
- **1.2** `input()`/`measure()` frame-length probe: incomplete frame → 0; multi-bulk
  length accumulation; nested arrays; MAX_DEPTH (64) boundary + overflow message.
- **1.3** `encode()` Client-relevant cases: nested one-level flatten, binary-safe
  values, numeric args, empty arg, large bulk.

### Group 2 — `Client.php` pure-logic Unit tests (Track B, no event loop)
- **2.1** `__call()` trailing-callable rules incl. the `randomKey/multi/exec/discard`
  exception list and the `count>1` gate; method-name uppercasing.
- **2.2** `dispatcher()` dot vs space prefix; trailing-callable pop; `rawCommand()`
  empty-args throws `\InvalidArgumentException`.
- **2.3** `*ScanAll` aggregation: cursor loop termination, accumulation, dedup,
  `zScanAll` score-string precision (drive with a stubbed/faked connection or by
  feeding crafted RESP through the reply path).
- **2.4** `handleUnsubscribeAck()` bookkeeping, `error()` formatting, `select()`/`auth()`
  arg shaping.

### Group 3 — Connection / lifecycle / server (Feature, both backends)
- **3.1** connect / `closeConnection` / `close` / reconnect-after-`quit`; `auth`
  success + failure; `select` invalid db.
- **3.2** no-arg server cmds that the old `__call` mis-handled: `ping/info/dbSize/time/
  flushDb/flushAll/hello/lastSave/save/role/bgSave` (assert real replies, both engines).

### Group 4 — Data-type commands sweep *(parallel-safe)*
- **4.1** strings/keys extras (getDel/getEx/substr/copy/touch/expireTime/pExpireTime).
- **4.2** modern list/set/zset/stream verbs (lMove/lMPop/zDiff/zMPop/xAutoClaim/xSetId/xAdd…).
- **4.3** SCAN family end-to-end against seeded data (scan/hScan/sScan/zScan + *All).
- Each: confirm both Dragonfly **and** Redis; log any divergence per §4.

### Group 5 — Module commands *(parallel-safe; engine-gated)*
- **5.1** JSON.* (dispatcher + 16 shortcuts).
- **5.2** Bloom / CMS / TopK.
- **5.3** RediSearch FT.* (create/search/aggregate/info/drop).
- **DECIDED:** the Redis leg uses **`redis/redis-stack-server`** (ships RedisJSON,
  RedisBloom — incl. CMS/TopK — and RediSearch) so JSON/Bloom/CMS/TopK/FT run on
  both engines. No module-based skips needed; any *behavioral* divergence still uses
  `skipOnBackend(..., reason)` with a logged reason.

### Group 6 — Pub/Sub, monitor, unsubscribe (Feature, both backends)
- **6.1** subscribe/pSubscribe/sSubscribe delivery via a second client (bounded loop).
- **6.2** unsubscribe/pUnsubscribe/sUnsubscribe lock-clearing, partial, no-op.
- **6.3** monitor stream with bound; `pubSub CHANNELS`; `sPublish`.

### Group 7 — Surface completeness & error paths
- **7.1** Diff the 290 `@method` declarations vs tested verbs; add missing assertions.
- **7.2** Systematic error-reply handling (WRONGTYPE, unknown command, syntax) →
  confirm the decode `['!', …]` path and how the client surfaces it.

### Group 8 — Revolt coroutine mode (dual-mode coverage)
- **8.1** Add a Revolt-driven worker variant (load `revolt/event-loop`) and a small
  set of tests asserting synchronous return values from `queueCommand()`'s
  suspension branch (`suspenstion()` + `onMessage` resume). Run in CI behind the
  `revolt/event-loop` dev dep.

### Group 9 — Coverage close-out
- **9.1** Re-run merged coverage; list residual uncovered lines in `Client.php`.
- **9.2** Add targeted cases to cross 95%, OR document each impractical line
  (e.g. fatal socket-error branches, daemonize paths) with a one-line justification.
  Ratchet the `--min` floor to the achieved number.

### Group 10 — Documentation & finalization
- **10.1** Update README (dual-backend instructions, the subprocess-coverage
  mechanism, compatibility matrix of engine divergences), CONTRIBUTING, CHANGELOG,
  coverage badges.
- **10.2** Final `make test-all` on both engines + merged coverage report; record numbers.

---

## 6. Execution Protocol (per Step) — the agent loop

Every Step runs through this **synchronous** loop. The orchestrator does NOT advance
to the next step until the current one exits the loop clean.

```
for each STEP in the active GROUP (sequential unless marked parallel-safe):

  1. IMPLEMENT
     └─ spawn  coder sub-agent  (synchronous; Edit/Write/Bash)
        input:  this step's scope + the §3 gap analysis + §3/§5 checks
                + project rules (Pest; no `@var $this` in closures, use free
                  helpers in tests/Pest.php; RESP/dual-mode details; git ritual)
        action: implement tests / infra; run `make test-all` (BOTH backends)
                AND merged coverage until green locally.
        output: files changed + test results for BOTH engines + coverage delta.

  2. REVIEW  ───────────────────────────┐
     └─ spawn reviewer sub-agent (synchronous, read-only)
        checks: correctness; real assertions (no tautologies); BOTH-backend
                coverage; bounded loops (pub/sub/monitor must self-terminate);
                no silent skips; project conventions; that the step's checks
                are actually covered; coverage did not regress.
        output: PASS  |  FINDINGS[]                                           │
                                                                             │
  3. IF findings:                                                            │
       └─ spawn fix sub-agent (synchronous) with the findings;              │
          action: fix + re-run `make test-all` + coverage.                  │
       └─ GOTO 2 (re-review)  ───────────────────────────────────────────────┘

  4. loop 2↔3 until reviewer returns PASS with zero findings
     (HARD CAP: 3 fix↔review cycles; if still failing, STOP and surface the
      open findings to the human — do not thrash).

after ALL steps in the GROUP pass:

  5. DOCUMENT
     └─ spawn doc-updater sub-agent (synchronous; Edit/Write)
        action: review the group's diff; update README / CONTRIBUTING / CHANGELOG
                / docs / badges for what changed.
        then:   one reviewer pass on the doc changes (loop if findings).

  6. COMMIT the group (per project git ritual: origin master, NO Co-Authored-By
     line) once the doc-updater pass is clean.
```

### Guardrails
- **Both backends mandatory:** a test green on Dragonfly but red/skipped on Redis
  (or vice-versa) is a *finding*, not a pass — unless the divergence is documented
  per §4 with `skipOnBackend(... , reason)`.
- **No silent skips:** every skip carries a reason and is listed in README Compatibility.
- **Coverage monotonic:** no group may decrease merged coverage; Group 9 enforces ≥95%
  or documents exceptions.
- **Bounded async:** any pub/sub, monitor, or scan-loop test must have a timeout so the
  worker can't hang CI.

### Suggested agent types
| Role | Agent |
|---|---|
| coder / fix | `oac:coder-agent` or general coding agent (Edit/Write/Bash) |
| reviewer | `feature-dev:code-reviewer` or `oac:code-reviewer` (read-only) |
| doc-updater | general agent (Edit/Write) |

---

## 7. TL;DR order of attack
1. **Group 0** — fix subprocess coverage merge **and** add the Redis backend. Nothing
   else matters until `Client.php` shows a real number and both engines run green.
2. **Group 1** — push `Protocols/Redis.php` to ~100% (cheap in-process wins).
3. **Group 2** — in-process Unit tests for `Client` pure logic.
4. **Groups 3–7** — Feature command sweeps on both engines (4 & 5 parallel-safe).
5. **Group 8** — Revolt coroutine mode.
6. **Group 9** — close out to 95% (or document exceptions); ratchet the `--min` floor.
7. **Group 10** — docs, badges, final dual-backend + coverage run.

> **Realism note on 95%:** achievable for `Protocols/Redis.php` and `Exception.php`,
> and for `Client.php` *once Track A (subprocess coverage merge) lands* — the 198
> existing integration tests already exercise most of the 3,611 lines; they're simply
> invisible today. The residual <5% will be fatal-error / daemonize / socket-failure
> branches that are documented rather than forced.

---

## Coverage close-out (Group 9)

Group 9 pushed `src/Client.php` from **81.16%** (771/950, 179 uncovered) to a
final **92.32%** (877/950; methods **91.06%**, 112/123) and the merged total from
**82.82%** to a final **92.99%** (969/1042), with `Protocols/Redis.php` and
`Exception.php` at **100%** — and the coverage floor was ratcheted to **90**.
(An intermediate checkpoint in this group sat at Client.php 88.74% / total 89.83%
before the remaining targeted cases landed.) Per-engine final results: Dragonfly
**406 passed / 3 skipped**, Redis **409 passed / 0 skipped** (the Dragonfly skips
are the pre-existing, documented engine divergences — AUTH-with-no-password ×2 and
OBJECT-unknown-on-Dragonfly — not new; the Redis leg is skip-free).

### Tests added
- `tests/Unit/ClientShapingTier9Test.php` — in-process, no event loop
  (`newInstanceWithoutConstructor()` + reflection on `$_queue`; `process()` is
  inert while `$_connection` is null, so every command method just appends to
  the queue). Covers the reachable argument-shaping branches the integration
  suite cannot reach cheaply: `set()`→SETEX / `incr()`→INCRBY / `decr()`→DECRBY
  second forms; `sort()`/`sortRo()` option flattening + callable-options
  shortcut; `xAdd()` empty-message throw + `MAXLEN ~` shaping + callable-slot
  folding; the `hMGet()`/`hGetAll()` formatter non-array passthrough guards; the
  geo/eval read-only callable-options shortcuts; `hello()` extra-map arg; the
  `json()/bf()/cms()/topk()/ft()` trailing-null pops; the json* typed-getter
  callable-path shortcuts; `cmsMerge()` WEIGHTS + no-weights paths;
  `ftDropIndex()` DD branch + callable shortcut; `shutdown()` mode shaping +
  `$_quitting`; `monitor()` pending-stream early-return + rejection handler +
  +OK swallow.
- `tests/Unit/ClientSubscribeDispatchTest.php` — pulls the wrapped `$new_cb` out
  of the queued subscribe entry and invokes it directly: the `!$result`
  error-echo arm, the message/pmessage/smessage delivery arms, the
  subscribe-ack swallow, the unsubscribe-ack delegation, the `default:`
  unknown-type diagnostic sink (output-buffered + asserted), and the
  `assertNoActiveStream()` second-stream throw for all three subscribe families.
- `tests/Feature/ReconnectPrependTest.php` — the dead-port connection failure
  reported through the connection callback (covers the connect-timeout callback
  path 649–656). The onConnect SELECT prepend is documented below as
  impractical to drive deterministically.

### Residual uncovered lines (genuinely impractical — fault-injection only)
The residual set is:
`474,480,482-485,492,494-504,506,510-512,523,531,551,560-566,568,578-579,
586-587,589-591,629,638-639,644,658,852,888,929,1020,1118,2599-2600,
2943,2948,3074,3079,3197,3204,3336,3341`. Grouped:

1. **Wait-timeout queue eviction — 474, 480, 482–485, 492, 494–504, 506,
   510–512.** The 1-second `Timer::add` scan in the constructor only does real
   work when a non-blocking, non-stream command has sat in `$_queue` longer than
   `wait_timeout`. Forcing it needs a command that is *sent but never answered*
   for > wait_timeout seconds while the loop keeps ticking — i.e. a server that
   accepts the write then stalls. That is socket fault injection (a mock server
   that reads but never replies); not reproducible against a live
   Dragonfly/Redis within a bounded, non-flaky CI test. The subscribe/monitor
   skip (474/480) and blocking-command skip (485/492) are sub-branches of the
   same timer.

2. **connect() early-return / TLS / connection-FAILED onError — 523, 531, 551,
   560–566, 568.** 523 is the `if ($this->_connection) return;` guard, only
   reachable by calling `connect()` twice with no intervening close (the public
   API never does this in a single tick). 531 is the `ssl` transport assignment
   — needs an `['ssl' => true]` option pointed at a TLS-capable server, which
   neither test backend exposes. 551 is the *post*-connect `onError` echo
   installed inside `onConnect`, firing only on a mid-session socket error.
   560–566/568 are the synchronous connection-FAILED `onError` (set before
   `connect()`), which fires only when the OS refuses/aborts the TCP connect
   *synchronously*; on this stack a closed port is handled by the connect-timeout
   timer instead (649–656, now covered), so this arm needs a socket that RSTs
   mid-handshake. All socket fault injection.

3. **onClose auto-reconnect — 578, 579, 586, 587, 589, 590, 591.** The
   `onClose` handler's reconnect logic (immediate vs 5s-delayed `Timer::add`
   reconnect, and the reconnect-timer teardown) fires only when the *server*
   drops a live socket mid-session. Reconnect-after-`quit` is covered
   structurally by `QuitTest`, but the un-quit drop path that distinguishes the
   immediate-vs-delayed branch requires server-side fault injection.

4. **onMessage exception re-throw + reconnect-on-`!` + connect-timeout echo —
   629, 638, 639, 644, 658.** 629/644 are the user-callback `\Throwable` catch +
   post-pump re-throw; the catch fires only if a delivered-reply callback
   throws, and the re-throw then escapes onMessage (an uncaught throwable inside
   Workerman's read handler), which the fd-3 subprocess harness cannot observe
   without crashing the worker. 638/639 are the reconnect-on-`!` (server push
   error type) arm — requires the server to emit a `!`-typed frame, which
   neither engine does on demand. 658 is the connect-timeout `else { echo }`
   no-callback diagnostic sink (the with-callback arm 654–656 IS covered).

5. **Diagnostic `echo` sinks + stream-lock / multi-step returns — 852, 888, 929,
   1020, 1118.** 852/888/929 are the `echo 'unknow response type…'` default
   arms of subscribe/pSubscribe/sSubscribe — exercised in
   `ClientSubscribeDispatchTest` via an output buffer, so they show as covered in
   the Unit `.cov` but can drop out of the merged report under the
   subprocess-dump nondeterminism noted below. 1118 is `monitor()`'s
   already-LIVE stream early-return (the QUEUED-stream case is covered by the
   unit test). 1020 is a multi-step command return reached only under a specific
   re-queue ordering.

6. **Structurally dead cmsMerge callable branch — 2599, 2600.** `cmsMerge()`'s
   signature types `$weights` as `?array`, so a callable in that slot raises a
   `TypeError` at the call boundary BEFORE the `if (\is_callable($weights))`
   body can run — genuinely unreachable through any valid call (a pre-existing
   src quirk; not modified, per the tests-only constraint). The no-weights and
   with-weights `cmsMerge()` paths ARE covered by `ClientShapingTier9Test`.

7. **Coroutine `*ScanAll` error-abort + LIMIT-cap returns — 2943/2948 (scanAll),
   3074/3079 (hScanAll), 3197/3204 (sScanAll), 3336/3341 (zScanAll).** The
   `return false` (scan errored mid-walk) and `return $collected` (LIMIT hit
   mid-page) arms of the *synchronous coroutine* loops. The callback-mode
   equivalents of all four are fully covered by `ClientScanAllTest`; the
   coroutine happy-path of all four is covered by `CoroutineModeTest`. Hitting
   the coroutine error/limit arms needs, inside a Revolt fiber, a scan that
   errors on a later page or overflows a tiny LIMIT mid-page — both require
   crafting a multi-page server response under the fiber driver, which the live
   engines do not produce for small fixture datasets. Documented as
   covered-in-the-other-mode rather than forced.

These remainders are connection/socket fault paths, auto-reconnect timing,
diagnostic `echo` sinks, two structurally-dead lines (2599/2600), and
coroutine-only error arms whose logic is already proven in callback mode. The
`--min` floor in `bin/run-coverage.sh` is set to **87** — ~2.8pt below the
achieved merged total of 89.83% (Client.php 88.74%), leaving headroom for the
minor subprocess-dump merge nondeterminism the floor comment has always
anticipated (merging many per-worker `cov-*.cov` partials can shift a Unit-only
line or two — e.g. an `echo`-sink default arm — between runs; a coverage-tooling
artifact, not a test or client defect).
