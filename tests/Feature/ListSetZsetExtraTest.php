<?php

/*
|--------------------------------------------------------------------------
| Core list / set / sorted-set commands (Group 4 §4.2)
|--------------------------------------------------------------------------
|
| The "classic" data-type verbs that ModernCommandsTest does not touch and
| that previously appeared only as scaffolding (no dedicated assertion):
|
|   Lists:  LPUSH/RPUSH, LPOP/RPOP, LLEN, LRANGE, LINDEX, LSET,
|           LINSERT, LREM, LTRIM, RPOPLPUSH, LPUSHX/RPUSHX
|   Sets:   SADD/SREM, SMEMBERS, SISMEMBER, SCARD, SPOP, SRANDMEMBER,
|           SMOVE, SINTER, SUNION, SDIFF (+ STORE variants)
|   Zsets:  ZADD, ZRANGE, ZRANGEBYSCORE, ZREM, ZSCORE, ZRANK, ZREVRANK,
|           ZCARD, ZCOUNT, ZINCRBY, ZPOPMIN, ZPOPMAX, ZREVRANGE
|
| Keys use a pest:g4:lsz:<n>: prefix. No engine divergences observed:
| scores and float increments arrive as bulk strings on both engines.
*/

/* ---------------------------------------------------------------- Lists */

it('lPush/rPush build a list read back in order by lRange', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:1:l', function () use ($redis, $emit) {
            // rPush appends to the tail; lPush prepends to the head.
            $redis->rPush('pest:g4:lsz:1:l', 'b', 'c', function () use ($redis, $emit) {
                $redis->lPush('pest:g4:lsz:1:l', 'a', function ($len) use ($redis, $emit) {
                    $redis->lRange('pest:g4:lsz:1:l', 0, -1, function ($items) use ($emit, $len) {
                        $emit(['len' => $len, 'items' => $items]);
                    });
                });
            });
        });
    PHP);

    expect($result['len'])->toBe(3);
    expect($result['items'])->toBe(['a', 'b', 'c']);
});

it('lPop/rPop remove from each end and lLen tracks the size', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:2:l', function () use ($redis, $emit) {
            $redis->rPush('pest:g4:lsz:2:l', 'a', 'b', 'c', function () use ($redis, $emit) {
                $redis->lPop('pest:g4:lsz:2:l', function ($head) use ($redis, $emit) {
                    $redis->rPop('pest:g4:lsz:2:l', function ($tail) use ($redis, $emit, $head) {
                        $redis->lLen('pest:g4:lsz:2:l', function ($len) use ($emit, $head, $tail) {
                            $emit(['head' => $head, 'tail' => $tail, 'len' => $len]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['head'])->toBe('a');
    expect($result['tail'])->toBe('c');
    expect($result['len'])->toBe(1);
});

it('lIndex and lSet read and rewrite a single position', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:3:l', function () use ($redis, $emit) {
            $redis->rPush('pest:g4:lsz:3:l', 'a', 'b', 'c', function () use ($redis, $emit) {
                $redis->lIndex('pest:g4:lsz:3:l', 1, function ($before) use ($redis, $emit) {
                    $redis->lSet('pest:g4:lsz:3:l', 1, 'B', function ($ok) use ($redis, $emit, $before) {
                        $redis->lIndex('pest:g4:lsz:3:l', 1, function ($after) use ($emit, $before, $ok) {
                            $emit(['before' => $before, 'ok' => $ok, 'after' => $after]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['before'])->toBe('b');
    expect($result['ok'])->toBeTrue();
    expect($result['after'])->toBe('B');
});

it('lInsert places a value before a pivot', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:4:l', function () use ($redis, $emit) {
            $redis->rPush('pest:g4:lsz:4:l', 'a', 'c', function () use ($redis, $emit) {
                $redis->lInsert('pest:g4:lsz:4:l', 'BEFORE', 'c', 'b', function ($len) use ($redis, $emit) {
                    $redis->lRange('pest:g4:lsz:4:l', 0, -1, function ($items) use ($emit, $len) {
                        $emit(['len' => $len, 'items' => $items]);
                    });
                });
            });
        });
    PHP);

    expect($result['len'])->toBe(3);
    expect($result['items'])->toBe(['a', 'b', 'c']);
});

it('lRem removes matching elements and lTrim keeps a window', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:5:l', function () use ($redis, $emit) {
            $redis->rPush('pest:g4:lsz:5:l', 'x', 'a', 'x', 'b', 'x', function () use ($redis, $emit) {
                // Remove the first 2 'x' from head.
                $redis->lRem('pest:g4:lsz:5:l', 2, 'x', function ($removed) use ($redis, $emit) {
                    $redis->lRange('pest:g4:lsz:5:l', 0, -1, function ($afterRem) use ($redis, $emit, $removed) {
                        // Keep only index 0..1 of what remains (a, b, x).
                        $redis->lTrim('pest:g4:lsz:5:l', 0, 1, function ($trimOk) use ($redis, $emit, $removed, $afterRem) {
                            $redis->lRange('pest:g4:lsz:5:l', 0, -1, function ($afterTrim) use ($emit, $removed, $afterRem, $trimOk) {
                                $emit([
                                    'removed'   => $removed,
                                    'afterRem'  => $afterRem,
                                    'trimOk'    => $trimOk,
                                    'afterTrim' => $afterTrim,
                                ]);
                            });
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['removed'])->toBe(2);
    expect($result['afterRem'])->toBe(['a', 'b', 'x']);
    expect($result['trimOk'])->toBeTrue();
    expect($result['afterTrim'])->toBe(['a', 'b']);
});

it('rPopLPush moves the tail of one list to the head of another', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:6:src', 'pest:g4:lsz:6:dst', function () use ($redis, $emit) {
            $redis->rPush('pest:g4:lsz:6:src', 'a', 'b', 'c', function () use ($redis, $emit) {
                $redis->rPopLPush('pest:g4:lsz:6:src', 'pest:g4:lsz:6:dst', function ($moved) use ($redis, $emit) {
                    $redis->lRange('pest:g4:lsz:6:dst', 0, -1, function ($dst) use ($emit, $moved) {
                        $emit(['moved' => $moved, 'dst' => $dst]);
                    });
                });
            });
        });
    PHP);

    expect($result['moved'])->toBe('c');
    expect($result['dst'])->toBe(['c']);
});

it('lPushX/rPushX only push when the list already exists', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:7:missing', 'pest:g4:lsz:7:exists', function () use ($redis, $emit) {
            // No list yet -> rPushX is a no-op returning 0.
            $redis->rPushX('pest:g4:lsz:7:missing', 'x', function ($onMissing) use ($redis, $emit) {
                $redis->rPush('pest:g4:lsz:7:exists', 'a', function () use ($redis, $emit, $onMissing) {
                    // List exists -> lPushX prepends and returns the new length.
                    $redis->lPushX('pest:g4:lsz:7:exists', 'z', function ($onExisting) use ($redis, $emit, $onMissing) {
                        $redis->lRange('pest:g4:lsz:7:exists', 0, -1, function ($items) use ($emit, $onMissing, $onExisting) {
                            $emit(['onMissing' => $onMissing, 'onExisting' => $onExisting, 'items' => $items]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['onMissing'])->toBe(0);
    expect($result['onExisting'])->toBe(2);
    expect($result['items'])->toBe(['z', 'a']);
});

/* ----------------------------------------------------------------- Sets */

it('sAdd/sMembers/sCard/sIsMember/sRem cover basic set membership', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:8:s', function () use ($redis, $emit) {
            $redis->sAdd('pest:g4:lsz:8:s', 'a', 'b', 'c', function ($added) use ($redis, $emit) {
                $redis->sCard('pest:g4:lsz:8:s', function ($card) use ($redis, $emit, $added) {
                    $redis->sIsMember('pest:g4:lsz:8:s', 'b', function ($isB) use ($redis, $emit, $added, $card) {
                        $redis->sRem('pest:g4:lsz:8:s', 'b', function ($removed) use ($redis, $emit, $added, $card, $isB) {
                            $redis->sMembers('pest:g4:lsz:8:s', function ($members) use ($emit, $added, $card, $isB, $removed) {
                                sort($members);
                                $emit([
                                    'added'   => $added,
                                    'card'    => $card,
                                    'isB'     => $isB,
                                    'removed' => $removed,
                                    'members' => $members,
                                ]);
                            });
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['added'])->toBe(3);
    expect($result['card'])->toBe(3);
    expect($result['isB'])->toBe(1);
    expect($result['removed'])->toBe(1);
    expect($result['members'])->toBe(['a', 'c']);
});

it('sPop and sRandMember draw members from the set', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:9:s', function () use ($redis, $emit) {
            $redis->sAdd('pest:g4:lsz:9:s', 'a', 'b', 'c', function () use ($redis, $emit) {
                $redis->sRandMember('pest:g4:lsz:9:s', function ($rand) use ($redis, $emit) {
                    $redis->sPop('pest:g4:lsz:9:s', function ($popped) use ($redis, $emit, $rand) {
                        $redis->sCard('pest:g4:lsz:9:s', function ($card) use ($emit, $rand, $popped) {
                            $emit(['rand' => $rand, 'popped' => $popped, 'card' => $card]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['rand'])->toBeIn(['a', 'b', 'c']);
    expect($result['popped'])->toBeIn(['a', 'b', 'c']);
    // One member popped, two remain.
    expect($result['card'])->toBe(2);
});

it('sMove relocates a member between sets', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:10:a', 'pest:g4:lsz:10:b', function () use ($redis, $emit) {
            $redis->sAdd('pest:g4:lsz:10:a', 'x', 'y', function () use ($redis, $emit) {
                $redis->sMove('pest:g4:lsz:10:a', 'pest:g4:lsz:10:b', 'x', function ($moved) use ($redis, $emit) {
                    $redis->sIsMember('pest:g4:lsz:10:b', 'x', function ($inB) use ($redis, $emit, $moved) {
                        $redis->sIsMember('pest:g4:lsz:10:a', 'x', function ($inA) use ($emit, $moved, $inB) {
                            $emit(['moved' => $moved, 'inB' => $inB, 'inA' => $inA]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['moved'])->toBe(1);
    expect($result['inB'])->toBe(1);
    expect($result['inA'])->toBe(0);
});

it('sInter/sUnion/sDiff compute set algebra', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:11:a', 'pest:g4:lsz:11:b', function () use ($redis, $emit) {
            $redis->sAdd('pest:g4:lsz:11:a', 'a', 'b', 'c', function () use ($redis, $emit) {
                $redis->sAdd('pest:g4:lsz:11:b', 'b', 'c', 'd', function () use ($redis, $emit) {
                    $redis->sInter('pest:g4:lsz:11:a', 'pest:g4:lsz:11:b', function ($inter) use ($redis, $emit) {
                        $redis->sUnion('pest:g4:lsz:11:a', 'pest:g4:lsz:11:b', function ($union) use ($redis, $emit, $inter) {
                            $redis->sDiff('pest:g4:lsz:11:a', 'pest:g4:lsz:11:b', function ($diff) use ($emit, $inter, $union) {
                                sort($inter); sort($union); sort($diff);
                                $emit(['inter' => $inter, 'union' => $union, 'diff' => $diff]);
                            });
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['inter'])->toBe(['b', 'c']);
    expect($result['union'])->toBe(['a', 'b', 'c', 'd']);
    expect($result['diff'])->toBe(['a']);
});

it('sInterStore/sUnionStore/sDiffStore persist results and return the cardinality', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:12:a', 'pest:g4:lsz:12:b', 'pest:g4:lsz:12:i', 'pest:g4:lsz:12:u', 'pest:g4:lsz:12:d', function () use ($redis, $emit) {
            $redis->sAdd('pest:g4:lsz:12:a', 'a', 'b', 'c', function () use ($redis, $emit) {
                $redis->sAdd('pest:g4:lsz:12:b', 'b', 'c', 'd', function () use ($redis, $emit) {
                    $redis->sInterStore('pest:g4:lsz:12:i', 'pest:g4:lsz:12:a', 'pest:g4:lsz:12:b', function ($iCard) use ($redis, $emit) {
                        $redis->sUnionStore('pest:g4:lsz:12:u', 'pest:g4:lsz:12:a', 'pest:g4:lsz:12:b', function ($uCard) use ($redis, $emit, $iCard) {
                            $redis->sDiffStore('pest:g4:lsz:12:d', 'pest:g4:lsz:12:a', 'pest:g4:lsz:12:b', function ($dCard) use ($emit, $iCard, $uCard) {
                                $emit(['i' => $iCard, 'u' => $uCard, 'd' => $dCard]);
                            });
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['i'])->toBe(2); // {b,c}
    expect($result['u'])->toBe(4); // {a,b,c,d}
    expect($result['d'])->toBe(1); // {a}
});

/* ---------------------------------------------------------- Sorted sets */

it('zAdd/zRange/zCard/zScore build and read a sorted set', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:13:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:g4:lsz:13:z', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:g4:lsz:13:z', 3, 'c', function () use ($redis, $emit) {
                    $redis->zAdd('pest:g4:lsz:13:z', 2, 'b', function () use ($redis, $emit) {
                        $redis->zRange('pest:g4:lsz:13:z', 0, -1, function ($range) use ($redis, $emit) {
                            $redis->zCard('pest:g4:lsz:13:z', function ($card) use ($redis, $emit, $range) {
                                $redis->zScore('pest:g4:lsz:13:z', 'b', function ($score) use ($emit, $range, $card) {
                                    $emit(['range' => $range, 'card' => $card, 'score' => $score]);
                                });
                            });
                        });
                    });
                });
            });
        });
    PHP);

    // Default ZRANGE is ascending by score.
    expect($result['range'])->toBe(['a', 'b', 'c']);
    expect($result['card'])->toBe(3);
    // ZSCORE returns a bulk string on both engines.
    expect($result['score'])->toBe('2');
});

it('zRangeByScore filters by score window', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:14:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:g4:lsz:14:z', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:g4:lsz:14:z', 2, 'b', function () use ($redis, $emit) {
                    $redis->zAdd('pest:g4:lsz:14:z', 3, 'c', function () use ($redis, $emit) {
                        // Inclusive 2..3 -> b, c.
                        $redis->zRangeByScore('pest:g4:lsz:14:z', 2, 3, function ($range) use ($emit) {
                            $emit($range);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(['b', 'c']);
});

it('zRank/zRevRank/zRevRange report position and reverse order', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:15:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:g4:lsz:15:z', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:g4:lsz:15:z', 2, 'b', function () use ($redis, $emit) {
                    $redis->zAdd('pest:g4:lsz:15:z', 3, 'c', function () use ($redis, $emit) {
                        $redis->zRank('pest:g4:lsz:15:z', 'a', function ($rankA) use ($redis, $emit) {
                            $redis->zRevRank('pest:g4:lsz:15:z', 'a', function ($revRankA) use ($redis, $emit, $rankA) {
                                $redis->zRevRange('pest:g4:lsz:15:z', 0, -1, function ($rev) use ($emit, $rankA, $revRankA) {
                                    $emit(['rankA' => $rankA, 'revRankA' => $revRankA, 'rev' => $rev]);
                                });
                            });
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['rankA'])->toBe(0);    // lowest score -> rank 0
    expect($result['revRankA'])->toBe(2); // highest reverse index
    expect($result['rev'])->toBe(['c', 'b', 'a']);
});

it('zCount counts members within a score range', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:16:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:g4:lsz:16:z', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:g4:lsz:16:z', 2, 'b', function () use ($redis, $emit) {
                    $redis->zAdd('pest:g4:lsz:16:z', 3, 'c', function () use ($redis, $emit) {
                        $redis->zCount('pest:g4:lsz:16:z', 2, 3, function ($n) use ($emit) {
                            $emit($n);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(2);
});

it('zIncrBy bumps a member score and returns the new score', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:17:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:g4:lsz:17:z', 5, 'm', function () use ($redis, $emit) {
                $redis->zIncrBy('pest:g4:lsz:17:z', 2.5, 'm', function ($newScore) use ($emit) {
                    $emit($newScore);
                });
            });
        });
    PHP);

    // ZINCRBY returns the resulting score as a bulk string.
    expect($result)->toBe('7.5');
});

it('zPopMin/zPopMax pop the lowest and highest scored members', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:18:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:g4:lsz:18:z', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:g4:lsz:18:z', 2, 'b', function () use ($redis, $emit) {
                    $redis->zAdd('pest:g4:lsz:18:z', 3, 'c', function () use ($redis, $emit) {
                        $redis->zPopMin('pest:g4:lsz:18:z', function ($min) use ($redis, $emit) {
                            $redis->zPopMax('pest:g4:lsz:18:z', function ($max) use ($emit, $min) {
                                $emit(['min' => $min, 'max' => $max]);
                            });
                        });
                    });
                });
            });
        });
    PHP);

    // ZPOPMIN/MAX reply is a flat [member, score] pair.
    expect($result['min'])->toBe(['a', '1']);
    expect($result['max'])->toBe(['c', '3']);
});

it('zRem removes a member and returns the count removed', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:lsz:19:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:g4:lsz:19:z', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:g4:lsz:19:z', 2, 'b', function () use ($redis, $emit) {
                    $redis->zRem('pest:g4:lsz:19:z', 'a', 'missing', function ($removed) use ($redis, $emit) {
                        $redis->zCard('pest:g4:lsz:19:z', function ($card) use ($emit, $removed) {
                            $emit(['removed' => $removed, 'card' => $card]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['removed'])->toBe(1);
    expect($result['card'])->toBe(1);
});
