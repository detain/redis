<?php

/*
|--------------------------------------------------------------------------
| String / counter commands (Group 4 §4.1)
|--------------------------------------------------------------------------
|
| Core string and counter verbs with no prior dedicated Feature assertion:
|
|   APPEND, STRLEN, SETRANGE, GETRANGE, GETSET, INCRBY, DECRBY,
|   INCRBYFLOAT, SETEX, PSETEX, SETNX, GETBIT/SETBIT,
|   MSET/MGET (explicit mapCb path), MSETNX.
|
| NOTE: getMultiple() is advertised in @method as an MGET alias but has no
| implementation — __call() sends the literal verb GETMULTIPLE, which both
| engines reject ("ERR unknown command"). Reported to the reviewer; no test
| is written for it (would only pin a bug).
|
| All keys use a pest:g4:str:<n>: prefix.
|
| No engine divergences observed for this group — replies are byte-for-byte
| identical across Redis and Dragonfly (floats arrive as bulk strings).
*/

it('append extends a string and returns the new length', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:str:1:k', function () use ($redis, $emit) {
            $redis->append('pest:g4:str:1:k', 'foo', function ($len1) use ($redis, $emit) {
                $redis->append('pest:g4:str:1:k', 'bar', function ($len2) use ($redis, $emit, $len1) {
                    $redis->get('pest:g4:str:1:k', function ($value) use ($emit, $len1, $len2) {
                        $emit(['len1' => $len1, 'len2' => $len2, 'value' => $value]);
                    });
                });
            });
        });
    PHP);

    expect($result['len1'])->toBe(3);
    expect($result['len2'])->toBe(6);
    expect($result['value'])->toBe('foobar');
});

it('strLen returns the byte length of the stored value', function () {

    $result = runInWorker(<<<'PHP'
        $redis->set('pest:g4:str:2:k', 'hello', function () use ($redis, $emit) {
            $redis->strLen('pest:g4:str:2:k', function ($len) use ($emit) {
                $emit($len);
            });
        });
    PHP);

    expect($result)->toBe(5);
});

it('setRange overwrites part of a string at an offset', function () {

    $result = runInWorker(<<<'PHP'
        $redis->set('pest:g4:str:3:k', 'Hello World', function () use ($redis, $emit) {
            // Overwrite "World" starting at offset 6.
            $redis->setRange('pest:g4:str:3:k', 6, 'Redis', function ($len) use ($redis, $emit) {
                $redis->get('pest:g4:str:3:k', function ($value) use ($emit, $len) {
                    $emit(['len' => $len, 'value' => $value]);
                });
            });
        });
    PHP);

    expect($result['len'])->toBe(11);
    expect($result['value'])->toBe('Hello Redis');
});

it('getRange returns a substring by inclusive byte offsets', function () {

    $result = runInWorker(<<<'PHP'
        $redis->set('pest:g4:str:4:k', 'Hello World', function () use ($redis, $emit) {
            $redis->getRange('pest:g4:str:4:k', 0, 4, function ($slice) use ($emit) {
                $emit($slice);
            });
        });
    PHP);

    expect($result)->toBe('Hello');
});

it('getSet swaps the value and returns the previous one', function () {

    $result = runInWorker(<<<'PHP'
        $redis->set('pest:g4:str:5:k', 'old', function () use ($redis, $emit) {
            $redis->getSet('pest:g4:str:5:k', 'new', function ($prev) use ($redis, $emit) {
                $redis->get('pest:g4:str:5:k', function ($now) use ($emit, $prev) {
                    $emit(['prev' => $prev, 'now' => $now]);
                });
            });
        });
    PHP);

    expect($result['prev'])->toBe('old');
    expect($result['now'])->toBe('new');
});

it('incrBy and decrBy adjust an integer counter', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:str:6:k', function () use ($redis, $emit) {
            $redis->incrBy('pest:g4:str:6:k', 10, function ($afterIncr) use ($redis, $emit) {
                $redis->decrBy('pest:g4:str:6:k', 3, function ($afterDecr) use ($emit, $afterIncr) {
                    $emit(['incr' => $afterIncr, 'decr' => $afterDecr]);
                });
            });
        });
    PHP);

    expect($result['incr'])->toBe(10);
    expect($result['decr'])->toBe(7);
});

it('incrByFloat adds a fractional amount and returns a string', function () {

    $result = runInWorker(<<<'PHP'
        $redis->set('pest:g4:str:7:k', '10', function () use ($redis, $emit) {
            $redis->incrByFloat('pest:g4:str:7:k', 2.5, function ($value) use ($emit) {
                $emit($value);
            });
        });
    PHP);

    // INCRBYFLOAT replies with a bulk string, not a RESP integer.
    expect($result)->toBe('12.5');
});

it('setEx stores a value with a second TTL', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:str:8:k', function () use ($redis, $emit) {
            $redis->setEx('pest:g4:str:8:k', 100, 'v', function ($ok) use ($redis, $emit) {
                $redis->get('pest:g4:str:8:k', function ($value) use ($redis, $emit, $ok) {
                    $redis->ttl('pest:g4:str:8:k', function ($ttl) use ($emit, $ok, $value) {
                        $emit(['ok' => $ok, 'value' => $value, 'ttl' => $ttl]);
                    });
                });
            });
        });
    PHP);

    expect($result['ok'])->toBeTrue();
    expect($result['value'])->toBe('v');
    expect($result['ttl'])->toBeGreaterThan(0);
    expect($result['ttl'])->toBeLessThanOrEqual(100);
});

it('pSetEx stores a value with a millisecond TTL', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:str:9:k', function () use ($redis, $emit) {
            $redis->pSetEx('pest:g4:str:9:k', 100000, 'v', function ($ok) use ($redis, $emit) {
                $redis->pttl('pest:g4:str:9:k', function ($pttl) use ($emit, $ok) {
                    $emit(['ok' => $ok, 'pttl' => $pttl]);
                });
            });
        });
    PHP);

    expect($result['ok'])->toBeTrue();
    expect($result['pttl'])->toBeGreaterThan(0);
    expect($result['pttl'])->toBeLessThanOrEqual(100000);
});

it('setNx only sets when the key is absent', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:str:10:k', function () use ($redis, $emit) {
            $redis->setNx('pest:g4:str:10:k', 'first', function ($firstSet) use ($redis, $emit) {
                // Second attempt must be refused.
                $redis->setNx('pest:g4:str:10:k', 'second', function ($secondSet) use ($redis, $emit, $firstSet) {
                    $redis->get('pest:g4:str:10:k', function ($value) use ($emit, $firstSet, $secondSet) {
                        $emit(['first' => $firstSet, 'second' => $secondSet, 'value' => $value]);
                    });
                });
            });
        });
    PHP);

    expect($result['first'])->toBe(1);
    expect($result['second'])->toBe(0);
    expect($result['value'])->toBe('first');
});

it('setBit and getBit flip and read individual bits', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:str:11:k', function () use ($redis, $emit) {
            $redis->setBit('pest:g4:str:11:k', 7, 1, function ($prevBit) use ($redis, $emit) {
                $redis->getBit('pest:g4:str:11:k', 7, function ($bit7) use ($redis, $emit, $prevBit) {
                    $redis->getBit('pest:g4:str:11:k', 6, function ($bit6) use ($emit, $prevBit, $bit7) {
                        $emit(['prev' => $prevBit, 'bit7' => $bit7, 'bit6' => $bit6]);
                    });
                });
            });
        });
    PHP);

    expect($result['prev'])->toBe(0);
    expect($result['bit7'])->toBe(1);
    expect($result['bit6'])->toBe(0);
});

it('mSet then mGet round-trips multiple keys via the mapCb path', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:str:12:a', 'pest:g4:str:12:b', function () use ($redis, $emit) {
            $redis->mSet(['pest:g4:str:12:a' => '1', 'pest:g4:str:12:b' => '2'], function ($ok) use ($redis, $emit) {
                $redis->mGet(['pest:g4:str:12:a', 'pest:g4:str:12:b', 'pest:g4:str:12:missing'], function ($values) use ($emit, $ok) {
                    $emit(['ok' => $ok, 'values' => $values]);
                });
            });
        });
    PHP);

    // MSET returns +OK -> true.
    expect($result['ok'])->toBeTrue();
    expect($result['values'])->toBe(['1', '2', null]);
});

it('mSetNx sets all keys only when none exist', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:g4:str:13:a', 'pest:g4:str:13:b', function () use ($redis, $emit) {
            // First call: neither key exists -> sets both, returns 1.
            $redis->mSetNx(['pest:g4:str:13:a' => '1', 'pest:g4:str:13:b' => '2'], function ($firstOk) use ($redis, $emit) {
                // Second call: 'a' now exists -> sets nothing, returns 0.
                $redis->mSetNx(['pest:g4:str:13:a' => 'x', 'pest:g4:str:13:c' => 'y'], function ($secondOk) use ($redis, $emit, $firstOk) {
                    $redis->exists('pest:g4:str:13:c', function ($cExists) use ($emit, $firstOk, $secondOk) {
                        $emit(['first' => $firstOk, 'second' => $secondOk, 'cExists' => $cExists]);
                    });
                });
            });
        });
    PHP);

    expect($result['first'])->toBe(1);
    expect($result['second'])->toBe(0);
    // 'c' must not have been created because the whole MSETNX was refused.
    expect($result['cExists'])->toBe(0);
});
