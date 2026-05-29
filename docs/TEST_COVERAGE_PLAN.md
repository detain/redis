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
