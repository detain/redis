<?php

/*
|--------------------------------------------------------------------------
| Tier 4: Bitmap, Geo, and Scripting RO commands
|--------------------------------------------------------------------------
|
| Covers BITOP / BITPOS / BITFIELD plus the read-only Geo and Scripting
| variants. The five _RO verbs (BITFIELD_RO, GEORADIUS_RO,
| GEORADIUSBYMEMBER_RO, EVAL_RO, EVALSHA_RO) have explicit camelCase
| wrappers on Client — __call() would otherwise uppercase them into
| "BITFIELDRO" / "EVALRO" / etc. and the server would reject the verb.
|
| Unique prefix per test: pest:bge:tN: to avoid collisions across runs.
*/

it('bitOp performs AND across two bitmaps', function () {
    $result = runInWorker(<<<'PHP'
        // Two bitmaps; expected AND result is the intersection of bits.
        $redis->set('pest:bge:t1:a', "\xff\x0f", function () use ($redis, $emit) {
            $redis->set('pest:bge:t1:b', "\x0f\xff", function () use ($redis, $emit) {
                $redis->bitOp('AND', 'pest:bge:t1:dest', 'pest:bge:t1:a', 'pest:bge:t1:b', function ($len) use ($redis, $emit) {
                    $redis->get('pest:bge:t1:dest', function ($result) use ($len, $emit) {
                        $emit(['len' => $len, 'bytes' => bin2hex($result)]);
                    });
                });
            });
        });
    PHP);

    expect($result['len'])->toBe(2);
    // 0xff AND 0x0f = 0x0f, 0x0f AND 0xff = 0x0f
    expect($result['bytes'])->toBe('0f0f');
});

it('bitPos finds the position of the first set bit', function () {
    $result = runInWorker(<<<'PHP'
        $redis->set('pest:bge:t2:k', "\x00\xff\xf0", function () use ($redis, $emit) {
            $redis->bitPos('pest:bge:t2:k', 1, function ($pos) use ($emit) {
                $emit($pos);
            });
        });
    PHP);

    // 0x00 = bits 0-7 clear; 0xff starts at bit 8.
    expect($result)->toBe(8);
});

it('bitField increments a 5-bit signed counter', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:bge:t3:k', function () use ($redis, $emit) {
            $redis->bitField('pest:bge:t3:k', 'INCRBY', 'i5', 100, 1, function ($values) use ($redis, $emit) {
                $redis->bitField('pest:bge:t3:k', 'INCRBY', 'i5', 100, 1, function ($values2) use ($values, $emit) {
                    $emit(['first' => $values, 'second' => $values2]);
                });
            });
        });
    PHP);

    expect($result['first'])->toBe([1]);
    expect($result['second'])->toBe([2]);
});

it('bitFieldRo reads a 5-bit signed value', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:bge:t4:k', function () use ($redis, $emit) {
            $redis->bitField('pest:bge:t4:k', 'SET', 'i5', 0, 7, function () use ($redis, $emit) {
                $redis->bitFieldRo('pest:bge:t4:k', 'GET', 'i5', 0, function ($values) use ($emit) {
                    $emit($values);
                });
            });
        });
    PHP);

    expect($result)->toBe([7]);
});

it('geoSearch finds members within a radius from a coordinate', function () {
    // GEOSEARCH key FROMLONLAT lon lat BYRADIUS r unit [ASC|DESC] — the
    // RESP encoder flattens 1 level of array nesting, so each option
    // group can be passed as a sub-array.
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:bge:t5:geo', function () use ($redis, $emit) {
            $redis->geoAdd('pest:bge:t5:geo', -122.4194, 37.7749, 'sf', -73.9857, 40.7484, 'ny', function () use ($redis, $emit) {
                $redis->geoSearch('pest:bge:t5:geo', ['FROMLONLAT', -122.0, 37.0], ['BYRADIUS', 500, 'km'], ['ASC'], function ($members) use ($emit) {
                    $emit($members);
                });
            });
        });
    PHP);

    // 'sf' is ~110km from (-122, 37); 'ny' is ~4100km — well outside 500km.
    expect($result)->toContain('sf');
    expect($result)->not->toContain('ny');
});

it('geoRadiusRo lists members within a radius (read-only)', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:bge:t6:geo', function () use ($redis, $emit) {
            $redis->geoAdd('pest:bge:t6:geo', -122.4194, 37.7749, 'sf', function () use ($redis, $emit) {
                $redis->geoRadiusRo('pest:bge:t6:geo', -122.0, 37.0, 500, 'km', [], function ($members) use ($emit) {
                    $emit($members);
                });
            });
        });
    PHP);

    expect($result)->toBe(['sf']);
});

it('geoRadiusByMemberRo lists members near another member', function () {
    $result = runInWorker(<<<'PHP'
        $redis->del('pest:bge:t7:geo', function () use ($redis, $emit) {
            $redis->geoAdd('pest:bge:t7:geo', -122.4194, 37.7749, 'sf', -118.2437, 34.0522, 'la', function () use ($redis, $emit) {
                $redis->geoRadiusByMemberRo('pest:bge:t7:geo', 'sf', 1000, 'km', [], function ($members) use ($emit) {
                    sort($members);
                    $emit($members);
                });
            });
        });
    PHP);

    // SF -> LA is ~560 km; both fall within 1000 km of 'sf'.
    expect($result)->toBe(['la', 'sf']);
});

it('evalRo runs a read-only Lua script', function () {
    $result = runInWorker(<<<'PHP'
        $redis->evalRo('return ARGV[1]', ['hello-evalro'], 0, function ($r) use ($emit) {
            $emit($r);
        });
    PHP);

    expect($result)->toBe('hello-evalro');
});

it('evalShaRo runs a read-only Lua script by SHA', function () {
    $result = runInWorker(<<<'PHP'
        $redis->script('LOAD', 'return ARGV[1]', function ($sha) use ($redis, $emit) {
            $redis->evalShaRo($sha, ['hello-shaeval'], 0, function ($r) use ($emit) {
                $emit($r);
            });
        });
    PHP);

    expect($result)->toBe('hello-shaeval');
});
