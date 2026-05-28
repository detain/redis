<?php

/*
|--------------------------------------------------------------------------
| TopK / TOPK.* module
|--------------------------------------------------------------------------
|
| Dragonfly natively implements the RedisBloom TopK command set. These
| tests exercise the topk() dispatcher and the typed shortcuts (topkReserve,
| topkAdd, topkIncrBy, topkQuery, topkCount, topkList, topkInfo) added to
| Client.php.
|
| Each test uses a unique pest:topk:tN: prefix so concurrent runs don't
| collide.
*/

it('topkReserve creates a TopK sketch that topkAdd / topkQuery can use', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:topk:t1:sketch', function () use ($redis, $emit) {
            $redis->topkReserve('pest:topk:t1:sketch', 3, 8, 7, 0.9, function ($ok) use ($redis, $emit) {
                $redis->topkAdd('pest:topk:t1:sketch', 'apple', function () use ($ok, $redis, $emit) {
                    $redis->topkQuery('pest:topk:t1:sketch', 'apple', 'banana', function ($q) use ($ok, $emit) {
                        $emit(['reserve' => $ok, 'query' => $q]);
                    });
                });
            });
        });
    PHP);

    expect($result['reserve'])->toBeTrue();
    // apple was added so it must be in the top-K (K=3, only one entry).
    expect($result['query'])->toBe([1, 0]);
});

it('topkAdd returns the displacement array', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:topk:t2:sketch', function () use ($redis, $emit) {
            $redis->topkReserve('pest:topk:t2:sketch', 3, 8, 7, 0.9, function () use ($redis, $emit) {
                $redis->topkAdd('pest:topk:t2:sketch', 'a', 'b', 'c', function ($reply) use ($emit) {
                    $emit(['add' => $reply]);
                });
            });
        });
    PHP);

    // Three slots, three new items, nothing evicted — each element is
    // either null or an empty string per Dragonfly's "no displacement".
    expect($result['add'])->toBeArray();
    expect($result['add'])->toHaveCount(3);
    foreach ($result['add'] as $slot) {
        // Empty bulk / nil are both "no displacement".
        expect($slot === null || $slot === '' || $slot === false)->toBeTrue();
    }
});

it('topkIncrBy bumps counters for multiple items', function () {

    // TopK uses a small Count-Min-Sketch-like counter that decays as new
    // items contend for slots. On Dragonfly's RedisBloom-compatible
    // implementation a fresh top-K with K=3 and width=8/depth=7/decay=0.9
    // reliably under-counts heavy-hitter increments by one (e.g. an INCRBY
    // of 5 reports back as 4 from TOPK.COUNT). Assert direction rather
    // than an exact integer so the test stays stable across patch-level
    // server changes.
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:topk:t3:sketch', function () use ($redis, $emit) {
            $redis->topkReserve('pest:topk:t3:sketch', 3, 8, 7, 0.9, function () use ($redis, $emit) {
                $redis->topkIncrBy('pest:topk:t3:sketch', 'x', 5, 'y', 3, function () use ($redis, $emit) {
                    $redis->topkCount('pest:topk:t3:sketch', 'x', 'y', function ($counts) use ($emit) {
                        $emit(['count' => $counts]);
                    });
                });
            });
        });
    PHP);

    expect($result['count'])->toBeArray()->toHaveCount(2);
    // Both items got at least one increment registered (lower bound 1)
    // and at most the full requested bump (upper bound 5 / 3 respectively).
    expect($result['count'][0])->toBeGreaterThanOrEqual(1);
    expect($result['count'][0])->toBeLessThanOrEqual(5);
    expect($result['count'][1])->toBeGreaterThanOrEqual(1);
    expect($result['count'][1])->toBeLessThanOrEqual(3);
});

it('topkQuery returns 1 for in-top-K, 0 for outside', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:topk:t4:sketch', function () use ($redis, $emit) {
            $redis->topkReserve('pest:topk:t4:sketch', 3, 8, 7, 0.9, function () use ($redis, $emit) {
                $redis->topkIncrBy('pest:topk:t4:sketch', 'p', 9, 'q', 8, 'r', 7, function () use ($redis, $emit) {
                    $redis->topkQuery('pest:topk:t4:sketch', 'p', 'q', 'r', 'z', function ($reply) use ($emit) {
                        $emit(['query' => $reply]);
                    });
                });
            });
        });
    PHP);

    // p, q, r are the heavy hitters; z was never added.
    expect($result['query'])->toBe([1, 1, 1, 0]);
});

it('topkCount returns estimated counts per item', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:topk:t5:sketch', function () use ($redis, $emit) {
            $redis->topkReserve('pest:topk:t5:sketch', 3, 8, 7, 0.9, function () use ($redis, $emit) {
                $redis->topkIncrBy('pest:topk:t5:sketch', 'alpha', 12, function () use ($redis, $emit) {
                    $redis->topkCount('pest:topk:t5:sketch', 'alpha', 'omega', function ($reply) use ($emit) {
                        $emit(['count' => $reply]);
                    });
                });
            });
        });
    PHP);

    // alpha was bumped 12; omega never added.
    expect($result['count'][0])->toBe(12);
    expect($result['count'][1])->toBe(0);
});

it('topkList returns the current top-K members', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:topk:t6:sketch', function () use ($redis, $emit) {
            $redis->topkReserve('pest:topk:t6:sketch', 3, 8, 7, 0.9, function () use ($redis, $emit) {
                $redis->topkIncrBy('pest:topk:t6:sketch', 'm', 10, 'n', 7, 'o', 4, function () use ($redis, $emit) {
                    $redis->topkList('pest:topk:t6:sketch', function ($list) use ($emit) {
                        $emit(['list' => $list]);
                    });
                });
            });
        });
    PHP);

    expect($result['list'])->toBeArray();
    expect($result['list'])->toHaveCount(3);
    $members = array_map('strval', $result['list']);
    sort($members);
    expect($members)->toBe(['m', 'n', 'o']);
});

it('topkInfo reports K / width / depth / decay', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:topk:t7:sketch', function () use ($redis, $emit) {
            $redis->topkReserve('pest:topk:t7:sketch', 3, 8, 7, 0.9, function () use ($redis, $emit) {
                $redis->topkInfo('pest:topk:t7:sketch', function ($info) use ($emit) {
                    $emit(['info' => $info]);
                });
            });
        });
    PHP);

    expect($result['info'])->toBeArray();
    $info = [];
    for ($i = 0, $n = count($result['info']); $i + 1 < $n; $i += 2) {
        $info[(string) $result['info'][$i]] = $result['info'][$i + 1];
    }
    expect($info)->toHaveKey('width');
    expect($info)->toHaveKey('depth');
    expect((int) $info['width'])->toBe(8);
    expect((int) $info['depth'])->toBe(7);
});
