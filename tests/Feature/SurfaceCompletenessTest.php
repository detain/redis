<?php

/*
|--------------------------------------------------------------------------
| Group 7A: @method surface completeness
|--------------------------------------------------------------------------
|
| Covers @method-declared verbs that had NO existing assertion anywhere in
| tests/Feature or tests/Unit. Each case pins a real reply and is verified
| to behave identically on both Dragonfly (6379) and Redis (63790).
|
| Verbs covered here:
|   strings : bitCount
|   list    : blPop, brPop, bRPopLPush      (data-present, non-blocking path)
|   zset    : bzPopMax, bzPopMin            (data-present, non-blocking path)
|             zRangeByLex, zRemRangeByRank, zRemRangeByScore, zRevRangeByScore,
|             zinterstore, zunionstore
|   hll     : pfAdd, pfCount, pfMerge
|   geo     : geoDist, geoHash, geoPos
|   tx      : watch, unwatch
|   stream  : xAck, xClaim, xInfo, xPending
|
| Unique prefix per area: pest:g7:<area>:<n>: to avoid collisions (shared db0).
*/

it('bitCount counts set bits across a string', function () {
    $result = runInWorker(<<<'PHP'
        $redis->set('pest:g7:str:1', 'foobar', function () use ($redis, $emit) {
            $redis->bitCount('pest:g7:str:1', function ($count) use ($emit) {
                $emit($count);
            });
        });
    PHP);

    // "foobar" has 26 set bits.
    expect($result)->toBe(26);
});

it('blPop pops the head element when data is present', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:list:1', function () use ($redis, $emit) {
            $redis->rPush('pest:g7:list:1', 'a', 'b', 'c', function () use ($redis, $emit) {
                $redis->blPop('pest:g7:list:1', 1, function ($reply) use ($emit) {
                    $emit($reply);
                });
            });
        });
    PHP);

    // BLPOP returns [key, value]; head of [a,b,c] is "a".
    expect($result)->toBe(['pest:g7:list:1', 'a']);
});

it('brPop pops the tail element when data is present', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:list:2', function () use ($redis, $emit) {
            $redis->rPush('pest:g7:list:2', 'a', 'b', 'c', function () use ($redis, $emit) {
                $redis->brPop('pest:g7:list:2', 1, function ($reply) use ($emit) {
                    $emit($reply);
                });
            });
        });
    PHP);

    expect($result)->toBe(['pest:g7:list:2', 'c']);
});

it('bRPopLPush moves the tail of source to the head of destination', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:list:3a', 'pest:g7:list:3b', function () use ($redis, $emit) {
            $redis->rPush('pest:g7:list:3a', 'x', 'y', 'z', function () use ($redis, $emit) {
                $redis->bRPopLPush('pest:g7:list:3a', 'pest:g7:list:3b', 1, function ($moved) use ($redis, $emit) {
                    $redis->lRange('pest:g7:list:3b', 0, -1, function ($dst) use ($moved, $emit) {
                        $emit(['moved' => $moved, 'dst' => $dst]);
                    });
                });
            });
        });
    PHP);

    expect($result['moved'])->toBe('z');
    expect($result['dst'])->toBe(['z']);
});

it('bzPopMax pops the highest-scoring member when data is present', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:zset:1', function () use ($redis, $emit) {
            $redis->zAdd('pest:g7:zset:1', 1, 'a', 2, 'b', 3, 'c', function () use ($redis, $emit) {
                $redis->bzPopMax('pest:g7:zset:1', 1, function ($reply) use ($emit) {
                    $emit($reply);
                });
            });
        });
    PHP);

    // BZPOPMAX returns [key, member, score].
    expect($result[0])->toBe('pest:g7:zset:1');
    expect($result[1])->toBe('c');
    expect((float) $result[2])->toBe(3.0);
});

it('bzPopMin pops the lowest-scoring member when data is present', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:zset:2', function () use ($redis, $emit) {
            $redis->zAdd('pest:g7:zset:2', 1, 'a', 2, 'b', 3, 'c', function () use ($redis, $emit) {
                $redis->bzPopMin('pest:g7:zset:2', 1, function ($reply) use ($emit) {
                    $emit($reply);
                });
            });
        });
    PHP);

    expect($result[0])->toBe('pest:g7:zset:2');
    expect($result[1])->toBe('a');
    expect((float) $result[2])->toBe(1.0);
});

it('zRangeByLex returns members within a lexicographic range', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:zset:3', function () use ($redis, $emit) {
            $redis->zAdd('pest:g7:zset:3', 0, 'a', 0, 'b', 0, 'c', 0, 'd', function () use ($redis, $emit) {
                $redis->zRangeByLex('pest:g7:zset:3', '[a', '[c', function ($members) use ($emit) {
                    $emit($members);
                });
            });
        });
    PHP);

    expect($result)->toBe(['a', 'b', 'c']);
});

it('zRevRangeByScore returns members in descending score order', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:zset:4', function () use ($redis, $emit) {
            $redis->zAdd('pest:g7:zset:4', 1, 'a', 2, 'b', 3, 'c', function () use ($redis, $emit) {
                $redis->zRevRangeByScore('pest:g7:zset:4', 3, 1, function ($members) use ($emit) {
                    $emit($members);
                });
            });
        });
    PHP);

    expect($result)->toBe(['c', 'b', 'a']);
});

it('zRemRangeByRank removes members by rank range', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:zset:5', function () use ($redis, $emit) {
            $redis->zAdd('pest:g7:zset:5', 1, 'a', 2, 'b', 3, 'c', function () use ($redis, $emit) {
                $redis->zRemRangeByRank('pest:g7:zset:5', 0, 0, function ($removed) use ($redis, $emit) {
                    $redis->zRange('pest:g7:zset:5', 0, -1, function ($rest) use ($removed, $emit) {
                        $emit(['removed' => $removed, 'rest' => $rest]);
                    });
                });
            });
        });
    PHP);

    expect($result['removed'])->toBe(1);
    expect($result['rest'])->toBe(['b', 'c']);
});

it('zRemRangeByScore removes members by score range', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:zset:6', function () use ($redis, $emit) {
            $redis->zAdd('pest:g7:zset:6', 1, 'a', 2, 'b', 3, 'c', function () use ($redis, $emit) {
                $redis->zRemRangeByScore('pest:g7:zset:6', 2, 2, function ($removed) use ($redis, $emit) {
                    $redis->zRange('pest:g7:zset:6', 0, -1, function ($rest) use ($removed, $emit) {
                        $emit(['removed' => $removed, 'rest' => $rest]);
                    });
                });
            });
        });
    PHP);

    expect($result['removed'])->toBe(1);
    expect($result['rest'])->toBe(['a', 'c']);
});

it('zinterstore stores the intersection of two sorted sets', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:zset:7a', 'pest:g7:zset:7b', 'pest:g7:zset:7d', function () use ($redis, $emit) {
            $redis->zAdd('pest:g7:zset:7a', 1, 'a', 2, 'b', function () use ($redis, $emit) {
                $redis->zAdd('pest:g7:zset:7b', 3, 'a', 4, 'c', function () use ($redis, $emit) {
                    $redis->zinterstore('pest:g7:zset:7d', 2, 'pest:g7:zset:7a', 'pest:g7:zset:7b', function ($card) use ($redis, $emit) {
                        $redis->zScore('pest:g7:zset:7d', 'a', function ($score) use ($card, $emit) {
                            $emit(['card' => $card, 'score_a' => $score]);
                        });
                    });
                });
            });
        });
    PHP);

    // Only "a" is in both; default aggregate SUM => 1 + 3 = 4. The score comes
    // back as a numeric string / int over the JSON pipe, so compare as a float.
    expect($result['card'])->toBe(1);
    expect((float) $result['score_a'])->toBe(4.0);
});

it('zunionstore stores the union of two sorted sets', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:zset:8a', 'pest:g7:zset:8b', 'pest:g7:zset:8d', function () use ($redis, $emit) {
            $redis->zAdd('pest:g7:zset:8a', 1, 'a', 2, 'b', function () use ($redis, $emit) {
                $redis->zAdd('pest:g7:zset:8b', 3, 'a', 4, 'c', function () use ($redis, $emit) {
                    $redis->zunionstore('pest:g7:zset:8d', 2, 'pest:g7:zset:8a', 'pest:g7:zset:8b', function ($card) use ($emit) {
                        $emit($card);
                    });
                });
            });
        });
    PHP);

    // Union of {a,b} and {a,c} => {a,b,c}.
    expect($result)->toBe(3);
});

it('pfAdd, pfCount and pfMerge operate on HyperLogLog structures', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:hll:1', 'pest:g7:hll:2', 'pest:g7:hll:3', function () use ($redis, $emit) {
            $redis->pfAdd('pest:g7:hll:1', 'a', 'b', 'c', function ($added1) use ($redis, $emit) {
                $redis->pfAdd('pest:g7:hll:2', 'c', 'd', 'e', function () use ($redis, $emit, $added1) {
                    $redis->pfCount('pest:g7:hll:1', function ($count1) use ($redis, $emit, $added1) {
                        $redis->pfMerge('pest:g7:hll:3', 'pest:g7:hll:1', 'pest:g7:hll:2', function ($merged) use ($redis, $emit, $added1, $count1) {
                            $redis->pfCount('pest:g7:hll:3', function ($count3) use ($emit, $added1, $count1, $merged) {
                                $emit([
                                    'added1' => $added1,
                                    'count1' => $count1,
                                    'merged' => $merged,
                                    'count3' => $count3,
                                ]);
                            });
                        });
                    });
                });
            });
        });
    PHP);

    // pfAdd reports 1 when the registers changed.
    expect($result['added1'])->toBe(1);
    expect($result['count1'])->toBe(3);
    expect($result['merged'])->toBe(true);
    // Union of {a,b,c} and {c,d,e} has cardinality 5.
    expect($result['count3'])->toBe(5);
});

it('geoAdd then geoDist, geoHash and geoPos report geospatial data', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g7:geo:1', function () use ($redis, $emit) {
            $redis->geoAdd('pest:g7:geo:1', 13.361389, 38.115556, 'Palermo', 15.087269, 37.502669, 'Catania', function ($added) use ($redis, $emit) {
                $redis->geoDist('pest:g7:geo:1', 'Palermo', 'Catania', 'km', function ($dist) use ($redis, $emit, $added) {
                    $redis->geoHash('pest:g7:geo:1', 'Palermo', function ($hash) use ($redis, $emit, $added, $dist) {
                        $redis->geoPos('pest:g7:geo:1', 'Palermo', function ($pos) use ($emit, $added, $dist, $hash) {
                            $emit([
                                'added' => $added,
                                'dist'  => (float) $dist,
                                'hash'  => $hash,
                                'pos'   => $pos,
                            ]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['added'])->toBe(2);
    // ~166.27 km between the two cities; engines differ in precision so assert a band.
    expect($result['dist'])->toBeGreaterThan(166.0);
    expect($result['dist'])->toBeLessThan(167.0);
    // geohash is deterministic for the same coordinates.
    expect($result['hash'])->toBe(['sqc8b49rny0']);
    // geoPos returns [[lon, lat]]; coordinates round-trip with some precision loss.
    expect((float) $result['pos'][0][0])->toBeGreaterThan(13.3);
    expect((float) $result['pos'][0][0])->toBeLessThan(13.4);
});

it('watch and unwatch return OK', function () {
    $result = runInWorker(<<<'PHP'
        // NOTE: unwatch() takes no key, so $redis->unwatch($cb) would hit the
        // documented __call footgun (a lone callable with count(args) == 1 is
        // NOT popped as the callback). Route UNWATCH through rawCommand(), which
        // always pops a trailing callable regardless of arg count.
        $redis->set('pest:g7:tx:1', 'v', function () use ($redis, $emit) {
            $redis->watch('pest:g7:tx:1', function ($watched) use ($redis, $emit) {
                $redis->rawCommand('UNWATCH', function ($unwatched) use ($emit, $watched) {
                    $emit(['watched' => $watched, 'unwatched' => $unwatched]);
                });
            });
        });
    PHP);

    // Both WATCH and UNWATCH reply +OK, normalised to true by the client.
    expect($result['watched'])->toBe(true);
    expect($result['unwatched'])->toBe(true);
});

it('stream consumer-group verbs xPending, xAck, xClaim and xInfo work', function () {
    $result = runInWorker(<<<'PHP'
        $stream = 'pest:g7:strm:1';
        $redis->del($stream, function () use ($redis, $emit, $stream) {
            $redis->xAdd($stream, '1-1', ['field1' => 'v1'], function () use ($redis, $emit, $stream) {
                $redis->xAdd($stream, '2-1', ['field1' => 'v2'], function () use ($redis, $emit, $stream) {
                    $redis->xGroup('CREATE', $stream, 'g1', '0', function () use ($redis, $emit, $stream) {
                        // Deliver both entries to consumer c1 so they become pending.
                        $redis->rawCommand('XREADGROUP', 'GROUP', 'g1', 'c1', 'COUNT', 10, 'STREAMS', $stream, '>', function () use ($redis, $emit, $stream) {
                            $redis->xPending($stream, 'g1', function ($pending) use ($redis, $emit, $stream) {
                                // Summary form: [count, min-id, max-id, consumers].
                                $pendingCount = is_array($pending) ? (int) $pending[0] : 0;
                                $redis->xAck($stream, 'g1', '1-1', function ($acked) use ($redis, $emit, $stream, $pendingCount) {
                                    $redis->xClaim($stream, 'g1', 'c2', 0, '2-1', function ($claimed) use ($redis, $emit, $stream, $pendingCount, $acked) {
                                        $redis->xInfo('STREAM', $stream, function ($info) use ($emit, $pendingCount, $acked, $claimed) {
                                            // xInfo STREAM replies as a flat [k,v,k,v,...] list.
                                            $hasLength = is_array($info) && in_array('length', $info, true);
                                            $emit([
                                                'pending'   => $pendingCount,
                                                'acked'     => $acked,
                                                'claimed_n' => is_array($claimed) ? count($claimed) : 0,
                                                'info_ok'   => $hasLength,
                                            ]);
                                        });
                                    });
                                });
                            });
                        });
                    });
                });
            });
        });
    PHP);

    // Two entries delivered => 2 pending; XACK 1-1 => 1; XCLAIM 2-1 => 1 claimed.
    expect($result['pending'])->toBe(2);
    expect($result['acked'])->toBe(1);
    expect($result['claimed_n'])->toBe(1);
    expect($result['info_ok'])->toBe(true);
});
