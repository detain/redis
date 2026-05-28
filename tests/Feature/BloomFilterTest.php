<?php

/*
|--------------------------------------------------------------------------
| Bloom Filter / BF.* module
|--------------------------------------------------------------------------
|
| Dragonfly natively implements the RedisBloom Bloom-filter command set.
| These tests exercise the bf() dispatcher and the typed shortcuts
| (bfReserve, bfAdd, bfExists, bfMAdd, bfMExists) added to Client.php.
|
| Each test uses a unique pest:bf:tN: prefix so concurrent runs don't
| collide.
*/

it('bfReserve creates a Bloom filter that bfAdd / bfExists can use', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:bf:t1:bloom', function () use ($redis, $emit) {
            $redis->bfReserve('pest:bf:t1:bloom', 0.01, 1000, function ($ok) use ($redis, $emit) {
                $redis->bfAdd('pest:bf:t1:bloom', 'alice', function ($added) use ($ok, $redis, $emit) {
                    $redis->bfExists('pest:bf:t1:bloom', 'alice', function ($present) use ($ok, $added, $redis, $emit) {
                        $redis->bfExists('pest:bf:t1:bloom', 'bob', function ($missing) use ($ok, $added, $present, $emit) {
                            $emit([
                                'reserve' => $ok,
                                'added' => $added,
                                'alice_in' => $present,
                                'bob_in' => $missing,
                            ]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['reserve'])->toBeTrue();
    expect($result['added'])->toBe(1);
    expect($result['alice_in'])->toBe(1);
    expect($result['bob_in'])->toBe(0);
});

it('bfAdd is idempotent — second add of the same item returns 0', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:bf:t2:bloom', function () use ($redis, $emit) {
            $redis->bfReserve('pest:bf:t2:bloom', 0.01, 1000, function () use ($redis, $emit) {
                $redis->bfAdd('pest:bf:t2:bloom', 'carol', function ($first) use ($redis, $emit) {
                    $redis->bfAdd('pest:bf:t2:bloom', 'carol', function ($second) use ($first, $emit) {
                        $emit(['first' => $first, 'second' => $second]);
                    });
                });
            });
        });
    PHP);

    expect($result['first'])->toBe(1);
    expect($result['second'])->toBe(0);
});

it('bfExists returns 0 for never-added members', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:bf:t3:bloom', function () use ($redis, $emit) {
            $redis->bfReserve('pest:bf:t3:bloom', 0.01, 1000, function () use ($redis, $emit) {
                $redis->bfAdd('pest:bf:t3:bloom', 'dave', function () use ($redis, $emit) {
                    $redis->bfExists('pest:bf:t3:bloom', 'eve', function ($reply) use ($emit) {
                        $emit(['exists' => $reply]);
                    });
                });
            });
        });
    PHP);

    expect($result['exists'])->toBe(0);
});

it('bfMAdd inserts multiple items in one round-trip', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:bf:t4:bloom', function () use ($redis, $emit) {
            $redis->bfReserve('pest:bf:t4:bloom', 0.01, 1000, function () use ($redis, $emit) {
                $redis->bfMAdd('pest:bf:t4:bloom', 'a', 'b', 'c', function ($reply) use ($emit) {
                    $emit(['madd' => $reply]);
                });
            });
        });
    PHP);

    // All three are new — Bloom filter returns 1 per slot.
    expect($result['madd'])->toBe([1, 1, 1]);
});

it('bfMExists tests multiple items in one round-trip', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:bf:t5:bloom', function () use ($redis, $emit) {
            $redis->bfReserve('pest:bf:t5:bloom', 0.01, 1000, function () use ($redis, $emit) {
                $redis->bfMAdd('pest:bf:t5:bloom', 'foo', 'bar', function () use ($redis, $emit) {
                    $redis->bfMExists('pest:bf:t5:bloom', 'foo', 'baz', 'bar', function ($reply) use ($emit) {
                        $emit(['mexists' => $reply]);
                    });
                });
            });
        });
    PHP);

    // foo / bar present, baz absent.
    expect($result['mexists'])->toBe([1, 0, 1]);
});
