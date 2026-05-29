<?php

/*
|--------------------------------------------------------------------------
| Hash & Stream core commands (Group 4 §4.2)
|--------------------------------------------------------------------------
|
| Hash and stream verbs lacking a dedicated Feature assertion:
|
|   Hashes:  HSET/HGET, HGETALL, HDEL, HEXISTS, HKEYS, HVALS, HLEN,
|            HINCRBY, HINCRBYFLOAT, HMSET (explicit), HMGET (explicit),
|            HSETNX, HSTRLEN
|   Streams: XADD, XLEN, XRANGE, XREVRANGE, XREAD, XDEL, XTRIM
|
| Keys use a pest:g4:hs:<n>: prefix. No engine divergences observed.
*/

/* --------------------------------------------------------------- Hashes */

it('hSet/hGet/hExists/hLen handle individual fields', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:1:h', function () use ($redis, $emit) {
            $redis->hSet('pest:g4:hs:1:h', 'f1', 'v1', function ($added) use ($redis, $emit) {
                $redis->hGet('pest:g4:hs:1:h', 'f1', function ($value) use ($redis, $emit, $added) {
                    $redis->hExists('pest:g4:hs:1:h', 'f1', function ($exists) use ($redis, $emit, $added, $value) {
                        $redis->hLen('pest:g4:hs:1:h', function ($len) use ($emit, $added, $value, $exists) {
                            $emit(['added' => $added, 'value' => $value, 'exists' => $exists, 'len' => $len]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['added'])->toBe(1);
    expect($result['value'])->toBe('v1');
    expect($result['exists'])->toBe(1);
    expect($result['len'])->toBe(1);
});

it('hMSet/hGetAll round-trip an associative map', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:2:h', function () use ($redis, $emit) {
            $redis->hMSet('pest:g4:hs:2:h', ['a' => '1', 'b' => '2', 'c' => '3'], function ($ok) use ($redis, $emit) {
                $redis->hGetAll('pest:g4:hs:2:h', function ($all) use ($emit, $ok) {
                    $emit(['ok' => $ok, 'all' => $all]);
                });
            });
        });
    PHP);

    expect($result['ok'])->toBeTrue();
    // hGetAll() formats the flat reply into an associative array.
    expect($result['all'])->toBe(['a' => '1', 'b' => '2', 'c' => '3']);
});

it('hMGet returns the requested fields keyed by field name', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:3:h', function () use ($redis, $emit) {
            $redis->hMSet('pest:g4:hs:3:h', ['a' => '1', 'b' => '2'], function () use ($redis, $emit) {
                $redis->hMGet('pest:g4:hs:3:h', ['a', 'b', 'missing'], function ($values) use ($emit) {
                    $emit($values);
                });
            });
        });
    PHP);

    // hMGet() combines the field names with the reply values.
    expect($result)->toBe(['a' => '1', 'b' => '2', 'missing' => null]);
});

it('hKeys/hVals list field names and values', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:4:h', function () use ($redis, $emit) {
            $redis->hMSet('pest:g4:hs:4:h', ['a' => '1', 'b' => '2'], function () use ($redis, $emit) {
                $redis->hKeys('pest:g4:hs:4:h', function ($keys) use ($redis, $emit) {
                    $redis->hVals('pest:g4:hs:4:h', function ($vals) use ($emit, $keys) {
                        sort($keys); sort($vals);
                        $emit(['keys' => $keys, 'vals' => $vals]);
                    });
                });
            });
        });
    PHP);

    expect($result['keys'])->toBe(['a', 'b']);
    expect($result['vals'])->toBe(['1', '2']);
});

it('hDel removes fields and returns the count deleted', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:5:h', function () use ($redis, $emit) {
            $redis->hMSet('pest:g4:hs:5:h', ['a' => '1', 'b' => '2', 'c' => '3'], function () use ($redis, $emit) {
                $redis->hDel('pest:g4:hs:5:h', 'a', 'b', 'missing', function ($deleted) use ($redis, $emit) {
                    $redis->hLen('pest:g4:hs:5:h', function ($len) use ($emit, $deleted) {
                        $emit(['deleted' => $deleted, 'len' => $len]);
                    });
                });
            });
        });
    PHP);

    expect($result['deleted'])->toBe(2);
    expect($result['len'])->toBe(1);
});

it('hIncrBy and hIncrByFloat adjust numeric fields', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:6:h', function () use ($redis, $emit) {
            $redis->hSet('pest:g4:hs:6:h', 'n', '10', function () use ($redis, $emit) {
                $redis->hIncrBy('pest:g4:hs:6:h', 'n', 5, function ($afterInt) use ($redis, $emit) {
                    $redis->hIncrByFloat('pest:g4:hs:6:h', 'n', 0.5, function ($afterFloat) use ($emit, $afterInt) {
                        $emit(['int' => $afterInt, 'float' => $afterFloat]);
                    });
                });
            });
        });
    PHP);

    // HINCRBY returns an integer; HINCRBYFLOAT a bulk string.
    expect($result['int'])->toBe(15);
    expect($result['float'])->toBe('15.5');
});

it('hSetNx only writes a field when it is absent', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:7:h', function () use ($redis, $emit) {
            $redis->hSetNx('pest:g4:hs:7:h', 'f', 'first', function ($firstOk) use ($redis, $emit) {
                $redis->hSetNx('pest:g4:hs:7:h', 'f', 'second', function ($secondOk) use ($redis, $emit, $firstOk) {
                    $redis->hGet('pest:g4:hs:7:h', 'f', function ($value) use ($emit, $firstOk, $secondOk) {
                        $emit(['first' => $firstOk, 'second' => $secondOk, 'value' => $value]);
                    });
                });
            });
        });
    PHP);

    expect($result['first'])->toBe(1);
    expect($result['second'])->toBe(0);
    expect($result['value'])->toBe('first');
});

it('hStrLen returns the byte length of a field value', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:8:h', function () use ($redis, $emit) {
            $redis->hSet('pest:g4:hs:8:h', 'f', 'hello', function () use ($redis, $emit) {
                $redis->hStrLen('pest:g4:hs:8:h', 'f', function ($len) use ($emit) {
                    $emit($len);
                });
            });
        });
    PHP);

    expect($result)->toBe(5);
});

/* -------------------------------------------------------------- Streams */

it('xAdd/xLen/xRange add and read stream entries in order', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:9:stream', function () use ($redis, $emit) {
            $redis->xAdd('pest:g4:hs:9:stream', '1-1', ['k' => 'v1'], function () use ($redis, $emit) {
                $redis->xAdd('pest:g4:hs:9:stream', '2-1', ['k' => 'v2'], function () use ($redis, $emit) {
                    $redis->xLen('pest:g4:hs:9:stream', function ($len) use ($redis, $emit) {
                        $redis->xRange('pest:g4:hs:9:stream', '-', '+', function ($entries) use ($emit, $len) {
                            $emit([
                                'len'      => $len,
                                'firstId'  => $entries[0][0],
                                'firstVal' => $entries[0][1],
                                'lastId'   => $entries[1][0],
                            ]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['len'])->toBe(2);
    expect($result['firstId'])->toBe('1-1');
    expect($result['firstVal'])->toBe(['k', 'v1']);
    expect($result['lastId'])->toBe('2-1');
});

it('xRevRange reads stream entries newest-first', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:10:stream', function () use ($redis, $emit) {
            $redis->xAdd('pest:g4:hs:10:stream', '1-1', ['k' => 'v1'], function () use ($redis, $emit) {
                $redis->xAdd('pest:g4:hs:10:stream', '2-1', ['k' => 'v2'], function () use ($redis, $emit) {
                    $redis->xRevRange('pest:g4:hs:10:stream', '+', '-', function ($entries) use ($emit) {
                        $emit(['firstId' => $entries[0][0], 'secondId' => $entries[1][0]]);
                    });
                });
            });
        });
    PHP);

    // Reverse order: newest (2-1) comes first.
    expect($result['firstId'])->toBe('2-1');
    expect($result['secondId'])->toBe('1-1');
});

it('xRead returns entries after a given id', function () {

    // XREAD COUNT 10 STREAMS key 0  -> all entries with id > 0.
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:11:stream', function () use ($redis, $emit) {
            $redis->xAdd('pest:g4:hs:11:stream', '1-1', ['k' => 'v1'], function () use ($redis, $emit) {
                $redis->xAdd('pest:g4:hs:11:stream', '2-1', ['k' => 'v2'], function () use ($redis, $emit) {
                    // Wire form: XREAD COUNT n STREAMS key id
                    $redis->xRead(['COUNT', 10, 'STREAMS', 'pest:g4:hs:11:stream', '0'], function ($reply) use ($emit) {
                        $emit($reply);
                    });
                });
            });
        });
    PHP);

    // XREAD reply: [[stream-name, [[id, [field, value]], ...]]]
    expect($result)->toBeArray();
    expect($result)->not->toBeEmpty();
    expect($result[0][0])->toBe('pest:g4:hs:11:stream');
    // Two entries returned.
    expect($result[0][1])->toHaveCount(2);
});

it('xDel removes specific stream entries', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:12:stream', function () use ($redis, $emit) {
            $redis->xAdd('pest:g4:hs:12:stream', '1-1', ['k' => 'v1'], function () use ($redis, $emit) {
                $redis->xAdd('pest:g4:hs:12:stream', '2-1', ['k' => 'v2'], function () use ($redis, $emit) {
                    $redis->xDel('pest:g4:hs:12:stream', '1-1', function ($deleted) use ($redis, $emit) {
                        $redis->xLen('pest:g4:hs:12:stream', function ($len) use ($emit, $deleted) {
                            $emit(['deleted' => $deleted, 'len' => $len]);
                        });
                    });
                });
            });
        });
    PHP);

    expect($result['deleted'])->toBe(1);
    expect($result['len'])->toBe(1);
});

it('xTrim caps a stream to MAXLEN', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:hs:13:stream', function () use ($redis, $emit) {
            $redis->xAdd('pest:g4:hs:13:stream', '1-1', ['k' => 'v1'], function () use ($redis, $emit) {
                $redis->xAdd('pest:g4:hs:13:stream', '2-1', ['k' => 'v2'], function () use ($redis, $emit) {
                    $redis->xAdd('pest:g4:hs:13:stream', '3-1', ['k' => 'v3'], function () use ($redis, $emit) {
                        // Wire form: XTRIM key MAXLEN n
                        $redis->xTrim('pest:g4:hs:13:stream', 'MAXLEN', 1, function ($trimmed) use ($redis, $emit) {
                            $redis->xLen('pest:g4:hs:13:stream', function ($len) use ($emit, $trimmed) {
                                $emit(['trimmed' => $trimmed, 'len' => $len]);
                            });
                        });
                    });
                });
            });
        });
    PHP);

    // Three entries trimmed down to one -> two removed.
    expect($result['trimmed'])->toBe(2);
    expect($result['len'])->toBe(1);
});
