<?php

/*
|--------------------------------------------------------------------------
| Tier 2 modern Redis commands (lists/sets/hashes/zsets/streams)
|--------------------------------------------------------------------------
|
| Covers commands that route through Client::__call() because each one has
| two or more wire args (so the count($args) > 1 branch picks up the
| trailing callback). No explicit method — only @method declarations on
| the class docblock and these integration tests confirming each one runs
| end-to-end against a live server.
|
| Lists:        LMOVE, LMPOP, LPOS, BLMOVE, BLMPOP
| Sets:         SMISMEMBER, SINTERCARD
| Hashes:       HRANDFIELD
| Sorted sets:  ZRANDMEMBER, ZMSCORE, ZDIFF, ZDIFFSTORE, ZINTER, ZINTERCARD,
|               ZUNION, ZRANGESTORE, ZMPOP, BZMPOP, ZREVRANGEBYLEX,
|               ZREMRANGEBYLEX, ZLEXCOUNT
| Streams:      XAUTOCLAIM, XSETID
|
| Each test uses a unique pest:modern:tN: prefix to avoid collisions. The
| assertions favour "did the command return a sane shape" over exhaustive
| semantic checks — some replies (LMPOP, ZMPOP, BLMPOP, XAUTOCLAIM) nest
| in implementation-specific ways across Redis/Dragonfly versions.
*/

it('lMove moves an element between two lists', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t1:src', 'pest:modern:t1:dst', function () use ($redis, $emit) {
            $redis->rPush('pest:modern:t1:src', 'a', 'b', 'c', function () use ($redis, $emit) {
                $redis->lMove('pest:modern:t1:src', 'pest:modern:t1:dst', 'LEFT', 'RIGHT', function ($moved) use ($emit) {
                    $emit($moved);
                });
            });
        });
    PHP);

    expect($result)->toBe('a');
});

it('lMPop pops elements from the first non-empty list', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t2:list', function () use ($redis, $emit) {
            $redis->rPush('pest:modern:t2:list', 'a', 'b', 'c', function () use ($redis, $emit) {
                // Wire form: LMPOP numkeys key [key ...] LEFT|RIGHT [COUNT n]
                $redis->lMPop([1, 'pest:modern:t2:list'], 'LEFT', function ($reply) use ($emit) {
                    $emit($reply);
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    // Reply shape: [key, [popped...]]
    expect($result[0])->toBe('pest:modern:t2:list');
    expect($result[1])->toBeArray();
});

it('lPos finds the index of an element', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t3:list', function () use ($redis, $emit) {
            $redis->rPush('pest:modern:t3:list', 'a', 'b', 'c', 'b', function () use ($redis, $emit) {
                $redis->lPos('pest:modern:t3:list', 'b', [], function ($index) use ($emit) {
                    $emit($index);
                });
            });
        });
    PHP);

    expect($result)->toBe(1);
});

it('blMove moves an element with a short timeout when data is present', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t4:src', 'pest:modern:t4:dst', function () use ($redis, $emit) {
            $redis->rPush('pest:modern:t4:src', 'x', 'y', function () use ($redis, $emit) {
                // Data already in src — server returns immediately, doesn't block.
                $redis->blMove('pest:modern:t4:src', 'pest:modern:t4:dst', 'LEFT', 'RIGHT', 0.1, function ($moved) use ($emit) {
                    $emit($moved);
                });
            });
        });
    PHP);

    expect($result)->toBe('x');
});

it('blMPop pops from a non-empty list within the timeout', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t5:list', function () use ($redis, $emit) {
            $redis->rPush('pest:modern:t5:list', 'one', 'two', function () use ($redis, $emit) {
                // Wire form: BLMPOP timeout numkeys key [key ...] LEFT|RIGHT [COUNT n]
                $redis->blMPop(0.1, [1, 'pest:modern:t5:list'], 'LEFT', function ($reply) use ($emit) {
                    $emit($reply);
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result[0])->toBe('pest:modern:t5:list');
});

it('sMIsMember returns one int per member', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t6:set', function () use ($redis, $emit) {
            $redis->sAdd('pest:modern:t6:set', 'a', 'b', function () use ($redis, $emit) {
                $redis->sMIsMember('pest:modern:t6:set', 'a', 'b', 'c', function ($flags) use ($emit) {
                    $emit($flags);
                });
            });
        });
    PHP);

    expect($result)->toBe([1, 1, 0]);
});

it('sInterCard returns the cardinality of the intersection', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t7:a', 'pest:modern:t7:b', function () use ($redis, $emit) {
            $redis->sAdd('pest:modern:t7:a', 'x', 'y', 'z', function () use ($redis, $emit) {
                $redis->sAdd('pest:modern:t7:b', 'x', 'y', 'q', function () use ($redis, $emit) {
                    // Wire form: SINTERCARD numkeys key [key ...] [LIMIT n]
                    $redis->sInterCard(2, ['pest:modern:t7:a', 'pest:modern:t7:b'], function ($n) use ($emit) {
                        $emit($n);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(2);
});

it('hRandField returns a field name from the hash', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t8:hash', function () use ($redis, $emit) {
            $redis->hMSet('pest:modern:t8:hash', ['f1' => 'v1', 'f2' => 'v2', 'f3' => 'v3'], function () use ($redis, $emit) {
                $redis->hRandField('pest:modern:t8:hash', 1, function ($field) use ($emit) {
                    $emit($field);
                });
            });
        });
    PHP);

    // With count=1 the reply is a one-element array containing a field name.
    expect($result)->toBeArray();
    expect($result)->toHaveCount(1);
    expect($result[0])->toBeIn(['f1', 'f2', 'f3']);
});

it('zRandMember returns a member of the sorted set', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t9:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t9:z', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t9:z', 2, 'b', function () use ($redis, $emit) {
                    $redis->zRandMember('pest:modern:t9:z', 1, function ($reply) use ($emit) {
                        $emit($reply);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(1);
    expect($result[0])->toBeIn(['a', 'b']);
});

it('zMScore returns scores for each requested member', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t10:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t10:z', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t10:z', 2, 'b', function () use ($redis, $emit) {
                    $redis->zMScore('pest:modern:t10:z', 'a', 'b', 'missing', function ($scores) use ($emit) {
                        $emit($scores);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(3);
    expect($result[0])->toBe('1');
    expect($result[1])->toBe('2');
    expect($result[2])->toBeNull();
});

it('zDiff returns members in the first set but not the others', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t11:a', 'pest:modern:t11:b', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t11:a', 1, 'x', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t11:a', 2, 'y', function () use ($redis, $emit) {
                    $redis->zAdd('pest:modern:t11:b', 1, 'x', function () use ($redis, $emit) {
                        // Wire form: ZDIFF numkeys key [key ...]
                        $redis->zDiff(2, ['pest:modern:t11:a', 'pest:modern:t11:b'], function ($diff) use ($emit) {
                            $emit($diff);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(['y']);
});

it('zDiffStore stores the diff and returns the cardinality', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t12:a', 'pest:modern:t12:b', 'pest:modern:t12:dst', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t12:a', 1, 'x', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t12:a', 2, 'y', function () use ($redis, $emit) {
                    $redis->zAdd('pest:modern:t12:b', 1, 'x', function () use ($redis, $emit) {
                        // Wire form: ZDIFFSTORE dst numkeys key [key ...]
                        $redis->zDiffStore('pest:modern:t12:dst', 2, ['pest:modern:t12:a', 'pest:modern:t12:b'], function ($n) use ($emit) {
                            $emit($n);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(1);
});

it('zInter returns the intersection of sorted sets', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t13:a', 'pest:modern:t13:b', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t13:a', 1, 'x', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t13:a', 2, 'y', function () use ($redis, $emit) {
                    $redis->zAdd('pest:modern:t13:b', 1, 'x', function () use ($redis, $emit) {
                        // Wire form: ZINTER numkeys key [key ...]
                        $redis->zInter(2, ['pest:modern:t13:a', 'pest:modern:t13:b'], function ($inter) use ($emit) {
                            $emit($inter);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(['x']);
});

it('zInterCard returns the cardinality of the intersection', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t14:a', 'pest:modern:t14:b', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t14:a', 1, 'x', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t14:a', 2, 'y', function () use ($redis, $emit) {
                    $redis->zAdd('pest:modern:t14:b', 1, 'x', function () use ($redis, $emit) {
                        $redis->zAdd('pest:modern:t14:b', 2, 'y', function () use ($redis, $emit) {
                            $redis->zInterCard(2, ['pest:modern:t14:a', 'pest:modern:t14:b'], function ($n) use ($emit) {
                                $emit($n);
                            });
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(2);
});

it('zUnion returns the union of sorted sets', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t15:a', 'pest:modern:t15:b', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t15:a', 1, 'x', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t15:b', 1, 'y', function () use ($redis, $emit) {
                    // Wire form: ZUNION numkeys key [key ...]
                    $redis->zUnion(2, ['pest:modern:t15:a', 'pest:modern:t15:b'], function ($union) use ($emit) {
                        $emit($union);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(2);
    sort($result);
    expect($result)->toBe(['x', 'y']);
});

it('zRangeStore copies a range from src into dst', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t16:src', 'pest:modern:t16:dst', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t16:src', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t16:src', 2, 'b', function () use ($redis, $emit) {
                    $redis->zAdd('pest:modern:t16:src', 3, 'c', function () use ($redis, $emit) {
                        // Wire form: ZRANGESTORE dst src min max
                        $redis->zRangeStore('pest:modern:t16:dst', 'pest:modern:t16:src', 0, -1, function ($n) use ($emit) {
                            $emit($n);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(3);
});

it('zMPop pops members from the first non-empty sorted set', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t17:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t17:z', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t17:z', 2, 'b', function () use ($redis, $emit) {
                    // Wire form: ZMPOP numkeys key [key ...] MIN|MAX [COUNT n]
                    $redis->zMPop(1, ['pest:modern:t17:z'], 'MIN', function ($reply) use ($emit) {
                        $emit($reply);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result[0])->toBe('pest:modern:t17:z');
});

it('bzMPop pops members from a non-empty sorted set within the timeout', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t18:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t18:z', 1, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t18:z', 2, 'b', function () use ($redis, $emit) {
                    // Wire form: BZMPOP timeout numkeys key [key ...] MIN|MAX [COUNT n]
                    $redis->bzMPop(0.1, 1, ['pest:modern:t18:z'], 'MIN', function ($reply) use ($emit) {
                        $emit($reply);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result[0])->toBe('pest:modern:t18:z');
});

it('zRevRangeByLex returns members in reverse lexicographic order', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t19:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t19:z', 0, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t19:z', 0, 'b', function () use ($redis, $emit) {
                    $redis->zAdd('pest:modern:t19:z', 0, 'c', function () use ($redis, $emit) {
                        $redis->zRevRangeByLex('pest:modern:t19:z', '+', '-', function ($members) use ($emit) {
                            $emit($members);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(['c', 'b', 'a']);
});

it('zRemRangeByLex removes members in the given lex range', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t20:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t20:z', 0, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t20:z', 0, 'b', function () use ($redis, $emit) {
                    $redis->zAdd('pest:modern:t20:z', 0, 'c', function () use ($redis, $emit) {
                        // Wire form: ZREMRANGEBYLEX key min max
                        $redis->zRemRangeByLex('pest:modern:t20:z', '[a', '[b', function ($removed) use ($emit) {
                            $emit($removed);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(2);
});

it('zLexCount counts members in a lex range', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t21:z', function () use ($redis, $emit) {
            $redis->zAdd('pest:modern:t21:z', 0, 'a', function () use ($redis, $emit) {
                $redis->zAdd('pest:modern:t21:z', 0, 'b', function () use ($redis, $emit) {
                    $redis->zAdd('pest:modern:t21:z', 0, 'c', function () use ($redis, $emit) {
                        $redis->zLexCount('pest:modern:t21:z', '-', '+', function ($n) use ($emit) {
                            $emit($n);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(3);
});

it('xAutoClaim transfers idle PEL entries to a new consumer', function () {

    // XADD wire form: XADD key id field value [field value ...]. The encoder
    // flattens 1 level of array nesting but only emits values, so pass the
    // field/value pair as a flat indexed array.
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t22:stream', function () use ($redis, $emit) {
            $redis->rawCommand('XADD', 'pest:modern:t22:stream', '*', 'k', 'v', function () use ($redis, $emit) {
                $redis->xGroup('CREATE', 'pest:modern:t22:stream', 'g1', '0', function () use ($redis, $emit) {
                    // Consumer 'c1' reads — entry now in PEL.
                    $redis->rawCommand('XREADGROUP', 'GROUP', 'g1', 'c1', 'COUNT', 1, 'STREAMS', 'pest:modern:t22:stream', '>', function () use ($redis, $emit) {
                        // Wire form: XAUTOCLAIM key group consumer min-idle-time start [COUNT n] [JUSTID]
                        $redis->xAutoClaim('pest:modern:t22:stream', 'g1', 'c2', 0, '0', function ($reply) use ($emit) {
                            $emit($reply);
                        });
                    });
                });
            });
        });
    PHP);

    // XAUTOCLAIM returns [next-cursor, claimed-entries, deleted-ids?]
    expect($result)->toBeArray();
    expect($result)->not->toBeEmpty();
});

it('xSetId updates the last-generated-id of a stream', function () {

    // Use rawCommand for XADD to avoid the @method's $arrMessage shape
    // mismatch (the encoder only emits array values, not keys).
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:modern:t23:stream', function () use ($redis, $emit) {
            $redis->rawCommand('XADD', 'pest:modern:t23:stream', '1-1', 'k', 'v', function () use ($redis, $emit) {
                // XSETID requires an id >= current top. Pick one larger than 1-1.
                $redis->xSetId('pest:modern:t23:stream', '999-0', function ($ok) use ($emit) {
                    $emit($ok);
                });
            });
        });
    PHP);

    expect($result)->toBeTrue();
});
