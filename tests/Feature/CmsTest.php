<?php

/*
|--------------------------------------------------------------------------
| Count-Min Sketch / CMS.* module
|--------------------------------------------------------------------------
|
| Dragonfly natively implements the RedisBloom Count-Min-Sketch command
| set. These tests exercise the cms() dispatcher and the typed shortcuts
| (cmsInitByDim, cmsInitByProb, cmsIncrBy, cmsQuery, cmsMerge, cmsInfo)
| added to Client.php.
|
| Each test uses a unique pest:cms:tN: prefix so concurrent runs don't
| collide.
*/

it('cmsInitByDim creates a sketch that cmsQuery can read from', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:cms:t1:sketch', function () use ($redis, $emit) {
            $redis->cmsInitByDim('pest:cms:t1:sketch', 1000, 5, function ($ok) use ($redis, $emit) {
                $redis->cmsIncrBy('pest:cms:t1:sketch', 'apple', 4, function () use ($ok, $redis, $emit) {
                    $redis->cmsQuery('pest:cms:t1:sketch', 'apple', 'pear', function ($q) use ($ok, $emit) {
                        $emit(['init' => $ok, 'query' => $q]);
                    });
                });
            });
        });
    PHP);

    expect($result['init'])->toBeTrue();
    // pear was never added — exact zero (Count-Min never under-counts).
    expect($result['query'])->toBe([4, 0]);
});

it('cmsInitByProb creates a sketch sized for a target error rate', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:cms:t2:sketch', function () use ($redis, $emit) {
            $redis->cmsInitByProb('pest:cms:t2:sketch', 0.001, 0.01, function ($ok) use ($redis, $emit) {
                $redis->cmsIncrBy('pest:cms:t2:sketch', 'banana', 7, function () use ($ok, $redis, $emit) {
                    $redis->cmsQuery('pest:cms:t2:sketch', 'banana', function ($q) use ($ok, $emit) {
                        $emit(['init' => $ok, 'query' => $q]);
                    });
                });
            });
        });
    PHP);

    expect($result['init'])->toBeTrue();
    expect($result['query'])->toBe([7]);
});

it('cmsIncrBy returns the new counts for each item', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:cms:t3:sketch', function () use ($redis, $emit) {
            $redis->cmsInitByDim('pest:cms:t3:sketch', 1000, 5, function () use ($redis, $emit) {
                $redis->cmsIncrBy('pest:cms:t3:sketch', 'a', 3, 'b', 5, function ($reply) use ($emit) {
                    $emit(['incr' => $reply]);
                });
            });
        });
    PHP);

    // CMS.INCRBY returns the post-increment estimate per item, ordered.
    expect($result['incr'])->toBe([3, 5]);
});

it('cmsQuery returns 0 for never-incremented items', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:cms:t4:sketch', function () use ($redis, $emit) {
            $redis->cmsInitByDim('pest:cms:t4:sketch', 1000, 5, function () use ($redis, $emit) {
                $redis->cmsIncrBy('pest:cms:t4:sketch', 'seen', 2, function () use ($redis, $emit) {
                    $redis->cmsQuery('pest:cms:t4:sketch', 'seen', 'unseen', function ($reply) use ($emit) {
                        $emit(['query' => $reply]);
                    });
                });
            });
        });
    PHP);

    expect($result['query'])->toBe([2, 0]);
});

it('cmsMerge sums counts from multiple source sketches into a destination', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del(
            'pest:cms:t5:s1', 'pest:cms:t5:s2', 'pest:cms:t5:dst',
            function () use ($redis, $emit) {
                $redis->cmsInitByDim('pest:cms:t5:s1', 1000, 5, function () use ($redis, $emit) {
                    $redis->cmsInitByDim('pest:cms:t5:s2', 1000, 5, function () use ($redis, $emit) {
                        $redis->cmsInitByDim('pest:cms:t5:dst', 1000, 5, function () use ($redis, $emit) {
                            $redis->cmsIncrBy('pest:cms:t5:s1', 'x', 4, function () use ($redis, $emit) {
                                $redis->cmsIncrBy('pest:cms:t5:s2', 'x', 6, function () use ($redis, $emit) {
                                    $redis->cmsMerge(
                                        'pest:cms:t5:dst',
                                        2,
                                        ['pest:cms:t5:s1', 'pest:cms:t5:s2'],
                                        null,
                                        function ($ok) use ($redis, $emit) {
                                            $redis->cmsQuery('pest:cms:t5:dst', 'x', function ($q) use ($ok, $emit) {
                                                $emit(['merge' => $ok, 'query' => $q]);
                                            });
                                        }
                                    );
                                });
                            });
                        });
                    });
                });
            }
        );
    PHP);

    expect($result['merge'])->toBeTrue();
    expect($result['query'])->toBe([10]);
});

it('cmsInfo returns sketch dimensions and a running count', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:cms:t6:sketch', function () use ($redis, $emit) {
            $redis->cmsInitByDim('pest:cms:t6:sketch', 1000, 5, function () use ($redis, $emit) {
                $redis->cmsIncrBy('pest:cms:t6:sketch', 'item', 3, function () use ($redis, $emit) {
                    $redis->cmsInfo('pest:cms:t6:sketch', function ($info) use ($emit) {
                        $emit(['info' => $info]);
                    });
                });
            });
        });
    PHP);

    // Dragonfly returns CMS.INFO as a flat [name, value, ...] array.
    expect($result['info'])->toBeArray();
    $info = [];
    for ($i = 0, $n = count($result['info']); $i + 1 < $n; $i += 2) {
        $info[(string) $result['info'][$i]] = $result['info'][$i + 1];
    }
    expect($info)->toHaveKey('width');
    expect($info)->toHaveKey('depth');
    expect((int) $info['width'])->toBe(1000);
    expect((int) $info['depth'])->toBe(5);
});
