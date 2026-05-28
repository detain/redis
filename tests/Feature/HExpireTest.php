<?php

/*
|--------------------------------------------------------------------------
| Tier 9 — HEXPIRE family
|--------------------------------------------------------------------------
|
| The HEXPIRE family applies TTLs to individual hash fields. Wire form for
| every member is `HCMD key [seconds|millis|ts] [NX|XX|GT|LT] FIELDS
| numfields field [field...]`. Replies are arrays of per-field integers.
|
| Dragonfly currently only ships HEXPIRE and HTTL — HPERSIST, HEXPIREAT,
| HEXPIRETIME, HPEXPIRE, HPTTL all reply -ERR unknown command. The unknown
| ones are tested via $redis->error() and skipped rather than asserting a
| fixed reply, so the suite stays green as Dragonfly catches up.
|
| Each test uses a unique pest:hexpire:tN: prefix so concurrent runs don't
| collide.
*/

it('hExpire applies a TTL to a single field', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hexpire:t1:hash';
        $redis->del($key, function () use ($redis, $emit, $key) {
            $redis->hSet($key, 'f1', 'v1', function () use ($redis, $emit, $key) {
                // HEXPIRE key seconds FIELDS numfields field [field...]
                $redis->hExpire($key, 100, 'FIELDS', 1, 'f1', function ($reply) use ($redis, $emit, $key) {
                    $redis->del($key, function () use ($emit, $reply) {
                        $emit($reply);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result[0])->toBe(1);
});

it('hTtl reads back the field TTL set by hExpire', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hexpire:t2:hash';
        $redis->del($key, function () use ($redis, $emit, $key) {
            $redis->hSet($key, 'f1', 'v1', function () use ($redis, $emit, $key) {
                $redis->hExpire($key, 200, 'FIELDS', 1, 'f1', function () use ($redis, $emit, $key) {
                    $redis->hTtl($key, 'FIELDS', 1, 'f1', function ($reply) use ($redis, $emit, $key) {
                        $redis->del($key, function () use ($emit, $reply) {
                            $emit($reply);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    // hTtl returns an integer per requested field. On Dragonfly the
    // remaining TTL is reported in seconds — just confirm it's a sane
    // positive integer (not -1 = no ttl, not -2 = no such field).
    expect($result[0])->toBeInt();
    expect($result[0])->toBeGreaterThan(0);
    expect($result[0])->toBeLessThanOrEqual(200);
});

it('hExpire reports -2 for fields that do not exist', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hexpire:t3:hash';
        $redis->del($key, function () use ($redis, $emit, $key) {
            $redis->hSet($key, 'f1', 'v1', function () use ($redis, $emit, $key) {
                // ghost is not set — Dragonfly should return -2 for it.
                $redis->hExpire($key, 100, 'FIELDS', 2, 'f1', 'ghost', function ($reply) use ($redis, $emit, $key) {
                    $redis->del($key, function () use ($emit, $reply) {
                        $emit($reply);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(2);
    expect($result[0])->toBe(1);
    expect($result[1])->toBe(-2);
});

it('hPersist removes the TTL — tolerates Dragonfly builds without the command', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hexpire:t4:hash';
        $redis->del($key, function () use ($redis, $emit, $key) {
            $redis->hSet($key, 'f1', 'v1', function () use ($redis, $emit, $key) {
                $redis->hExpire($key, 100, 'FIELDS', 1, 'f1', function () use ($redis, $emit, $key) {
                    $redis->hPersist($key, 'FIELDS', 1, 'f1', function ($reply) use ($redis, $emit, $key) {
                        $err = $redis->error();
                        $redis->del($key, function () use ($emit, $reply, $err) {
                            $emit(['reply' => $reply, 'err' => $err]);
                        });
                    });
                });
            });
        });
    PHP);

    // Dragonfly currently doesn't implement HPERSIST — accept the unknown-
    // command error path as well as the per-field integer-array reply that
    // future builds will return. Either way confirms we wired the verb
    // through __call() correctly.
    if (\is_string($result['err']) && $result['err'] !== '') {
        expect($result['err'])->toContain('unknown command');
        return;
    }
    expect($result['reply'])->toBeArray();
    expect($result['reply'][0])->toBe(1);
});

it('hExpireAt sets an absolute deadline — tolerates Dragonfly builds without the command', function () {

    $deadline = time() + 200;

    $result = runInWorker(<<<PHP
        \$key = 'pest:hexpire:t5:hash';
        \$redis->del(\$key, function () use (\$redis, \$emit, \$key) {
            \$redis->hSet(\$key, 'f1', 'v1', function () use (\$redis, \$emit, \$key) {
                \$redis->hExpireAt(\$key, {$deadline}, 'FIELDS', 1, 'f1', function (\$reply) use (\$redis, \$emit, \$key) {
                    \$err = \$redis->error();
                    \$redis->del(\$key, function () use (\$emit, \$reply, \$err) {
                        \$emit(['reply' => \$reply, 'err' => \$err]);
                    });
                });
            });
        });
    PHP);

    if (\is_string($result['err']) && $result['err'] !== '') {
        expect($result['err'])->toContain('unknown command');
        return;
    }
    expect($result['reply'])->toBeArray();
    expect($result['reply'][0])->toBe(1);
});

it('hExpireTime returns the absolute deadline — tolerates Dragonfly builds without the command', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hexpire:t6:hash';
        $redis->del($key, function () use ($redis, $emit, $key) {
            $redis->hSet($key, 'f1', 'v1', function () use ($redis, $emit, $key) {
                $redis->hExpire($key, 200, 'FIELDS', 1, 'f1', function () use ($redis, $emit, $key) {
                    $redis->hExpireTime($key, 'FIELDS', 1, 'f1', function ($reply) use ($redis, $emit, $key) {
                        $err = $redis->error();
                        $redis->del($key, function () use ($emit, $reply, $err) {
                            $emit(['reply' => $reply, 'err' => $err]);
                        });
                    });
                });
            });
        });
    PHP);

    if (\is_string($result['err']) && $result['err'] !== '') {
        expect($result['err'])->toContain('unknown command');
        return;
    }
    expect($result['reply'])->toBeArray();
    // The reported deadline is in unix-seconds and well in the future.
    expect($result['reply'][0])->toBeInt();
    expect($result['reply'][0])->toBeGreaterThan(\time());
});
