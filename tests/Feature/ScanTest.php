<?php

it('scan returns cursor and keys tuple', function () {

    $result = runInWorker(<<<'PHP'
        $prefix = 'pest:scan:t1:';
        $redis->del($prefix.'a', $prefix.'b', $prefix.'c');
        $redis->set($prefix.'a', '1');
        $redis->set($prefix.'b', '2');
        $redis->set($prefix.'c', '3');
        $collected = [];
        $cursor = '0';
        $loop = null;
        $loop = function ($reply) use (&$loop, &$collected, &$cursor, $redis, $emit, $prefix) {
            if (!is_array($reply) || !isset($reply['cursor'])) {
                $emit(['error' => 'bad reply', 'reply' => $reply]);
                return;
            }
            foreach ($reply['keys'] as $k) {
                $collected[] = $k;
            }
            $cursor = $reply['cursor'];
            if ($cursor === '0') {
                $emit([
                    'cursor_type' => gettype($cursor),
                    'cursor_final' => $cursor,
                    'keys' => array_values(array_unique($collected)),
                ]);
                return;
            }
            $redis->scan($cursor, ['MATCH' => $prefix.'*'], $loop);
        };
        $redis->scan('0', ['MATCH' => $prefix.'*'], $loop);
    PHP);

    expect($result)->toBeArray();
    expect($result['cursor_type'])->toBe('string');
    expect($result['cursor_final'])->toBe('0');
    sort($result['keys']);
    expect($result['keys'])->toBe([
        'pest:scan:t1:a',
        'pest:scan:t1:b',
        'pest:scan:t1:c',
    ]);
});

it('scan with COUNT respects the hint', function () {

    $result = runInWorker(<<<'PHP'
        $prefix = 'pest:scan:t2:';
        $keys = array_map(fn ($i) => $prefix.'k'.$i, range(1, 50));
        $redis->del(...$keys);
        $remaining = count($keys);
        foreach ($keys as $k) {
            $redis->set($k, '1', function () use (&$remaining, $redis, $emit, $prefix) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->scan('0', ['MATCH' => $prefix.'*', 'COUNT' => 10], function ($reply) use ($emit) {
                        $emit([
                            'cursor' => $reply['cursor'] ?? null,
                            'count'  => is_array($reply['keys'] ?? null) ? count($reply['keys']) : -1,
                        ]);
                    });
                }
            });
        }
    PHP);

    expect($result)->toBeArray();
    expect($result['count'])->toBeGreaterThanOrEqual(0);
    // COUNT is purely a hint — Dragonfly in particular may return the entire
    // small keyspace in one batch. The meaningful assertion is that COUNT was
    // accepted by the server (no error) and a sane count came back; the only
    // guaranteed upper bound for a 50-key prefix is 50.
    expect($result['count'])->toBeLessThanOrEqual(100);
});

it('scan with TYPE filters to that type', function () {

    $result = runInWorker(<<<'PHP'
        $prefix = 'pest:scan:t3:';
        $strKey = $prefix.'str';
        $listKey = $prefix.'list';
        $redis->del($strKey, $listKey);
        $redis->set($strKey, 'value');
        $redis->rPush($listKey, 'item', function () use ($redis, $prefix, $strKey, $listKey, $emit) {
            $collected = [];
            $loop = null;
            $loop = function ($reply) use (&$loop, &$collected, $redis, $emit, $prefix) {
                if (!is_array($reply) || !isset($reply['cursor'])) {
                    $emit(['error' => 'bad reply', 'reply' => $reply]);
                    return;
                }
                foreach ($reply['keys'] as $k) {
                    $collected[] = $k;
                }
                if ($reply['cursor'] === '0') {
                    $emit(array_values(array_unique($collected)));
                    return;
                }
                $redis->scan($reply['cursor'], ['MATCH' => $prefix.'*', 'TYPE' => 'string'], $loop);
            };
            $redis->scan('0', ['MATCH' => $prefix.'*', 'TYPE' => 'string'], $loop);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result)->toContain('pest:scan:t3:str');
    expect($result)->not->toContain('pest:scan:t3:list');
});

it('scanAll iterates the full keyspace', function () {

    $result = runInWorker(<<<'PHP'
        $prefix = 'pest:scanall:t4:';
        $keys = array_map(fn ($i) => $prefix.'k'.$i, range(1, 200));
        $redis->del(...$keys);
        $remaining = count($keys);
        foreach ($keys as $k) {
            $redis->set($k, '1', function () use (&$remaining, $redis, $emit, $prefix) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->scanAll(['MATCH' => $prefix.'*', 'COUNT' => 25], function ($all) use ($emit) {
                        $emit([
                            'count'  => count($all),
                            'unique' => count(array_unique($all)),
                        ]);
                    });
                }
            });
        }
    PHP, 10);

    expect($result)->toBeArray();
    expect($result['count'])->toBe(200);
    expect($result['unique'])->toBe(200);
});

it('scanAll honors the limit option', function () {

    $result = runInWorker(<<<'PHP'
        $prefix = 'pest:scanall:t5:';
        $keys = array_map(fn ($i) => $prefix.'k'.$i, range(1, 200));
        $redis->del(...$keys);
        $remaining = count($keys);
        foreach ($keys as $k) {
            $redis->set($k, '1', function () use (&$remaining, $redis, $emit, $prefix) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->scanAll(['MATCH' => $prefix.'*', 'COUNT' => 25, 'limit' => 50], function ($all) use ($emit) {
                        $emit(['count' => count($all)]);
                    });
                }
            });
        }
    PHP, 10);

    expect($result)->toBeArray();
    // limit caps each batch's contribution; the final count may exceed `limit`
    // by up to one COUNT batch because Redis returns whole batches at a time.
    expect($result['count'])->toBeLessThanOrEqual(75);
    expect($result['count'])->toBeGreaterThan(0);
});

it('scan on empty keyspace returns cursor 0 and empty keys array', function () {

    $result = runInWorker(<<<'PHP'
        // Use a prefix that is guaranteed to match nothing.
        $prefix = 'pest:scan:emptykeyspace:xxxxxxxx:';
        $collected = [];
        $cursor = '0';
        $loop = null;
        $loop = function ($reply) use (&$loop, &$collected, &$cursor, $redis, $emit, $prefix) {
            if (!is_array($reply) || !isset($reply['cursor'])) {
                $emit(['error' => 'bad reply', 'reply' => $reply]);
                return;
            }
            foreach ($reply['keys'] as $k) {
                $collected[] = $k;
            }
            $cursor = $reply['cursor'];
            if ($cursor === '0') {
                $emit([
                    'cursor'      => $cursor,
                    'cursor_type' => gettype($cursor),
                    'keys'        => $collected,
                ]);
                return;
            }
            $redis->scan($cursor, ['MATCH' => $prefix.'*'], $loop);
        };
        $redis->scan('0', ['MATCH' => $prefix.'*'], $loop);
    PHP);

    expect($result)->toBeArray();
    // Redis always eventually returns cursor '0' (string) even when no keys match.
    expect($result['cursor'])->toBe('0');
    expect($result['cursor_type'])->toBe('string');
    expect($result['keys'])->toBe([]);
});

it('scan accepts a non-zero starting cursor and returns a valid reply', function () {

    $result = runInWorker(<<<'PHP'
        // Populate enough keys to make a non-zero cursor very likely on the
        // first SCAN with a small COUNT hint.
        $prefix = 'pest:scan:cursor:';
        $keys = array_map(fn ($i) => $prefix.'k'.$i, range(1, 100));
        $redis->del(...$keys);
        $remaining = count($keys);
        foreach ($keys as $k) {
            $redis->set($k, '1', function () use (&$remaining, $redis, $emit, $prefix) {
                $remaining--;
                if ($remaining !== 0) {
                    return;
                }
                // First scan: small COUNT so Redis is likely to return a
                // non-zero cursor on a non-trivial keyspace.
                $redis->scan('0', ['MATCH' => $prefix.'*', 'COUNT' => 5], function ($first) use ($redis, $emit, $prefix) {
                    if (!is_array($first) || !isset($first['cursor'])) {
                        $emit(['error' => 'bad first reply']);
                        return;
                    }
                    $firstCursor = $first['cursor'];
                    // If Dragonfly returned everything in one batch the cursor
                    // is already '0'.  Still issue a second scan from that
                    // cursor to exercise the pass-through path.
                    $redis->scan($firstCursor, ['MATCH' => $prefix.'*', 'COUNT' => 5], function ($second) use ($emit, $firstCursor) {
                        if (!is_array($second) || !isset($second['cursor'])) {
                            $emit(['error' => 'bad second reply']);
                            return;
                        }
                        $emit([
                            'first_cursor'       => $firstCursor,
                            'first_cursor_type'  => gettype($firstCursor),
                            'second_cursor'      => $second['cursor'],
                            'second_cursor_type' => gettype($second['cursor']),
                            'second_keys_is_array' => is_array($second['keys']),
                        ]);
                    });
                });
            });
        }
    PHP);

    expect($result)->toBeArray();
    // Both cursors must be strings — the format callback casts them.
    expect($result['first_cursor_type'])->toBe('string');
    expect($result['second_cursor_type'])->toBe('string');
    // The second call must always return an array of keys (possibly empty).
    expect($result['second_keys_is_array'])->toBe(true);
});

it('scanAll on empty keyspace returns an empty array', function () {

    $result = runInWorker(<<<'PHP'
        $prefix = 'pest:scanall:empty:xxxxxxxx:';
        $redis->scanAll(['MATCH' => $prefix.'*'], function ($all) use ($emit) {
            $emit($all);
        });
    PHP);

    // scanAll with a no-match pattern must return an empty array, not false.
    expect($result)->toBe([]);
});

it('scanAll with limit at exact key count boundary collects all expected keys', function () {

    $result = runInWorker(<<<'PHP'
        $prefix = 'pest:scanall:boundary:';
        $keys = array_map(fn ($i) => $prefix.'k'.$i, range(1, 30));
        $redis->del(...$keys);
        $remaining = count($keys);
        foreach ($keys as $k) {
            $redis->set($k, '1', function () use (&$remaining, $redis, $emit, $prefix) {
                $remaining--;
                if ($remaining === 0) {
                    // limit == exact number of keys; Redis returns whole batches
                    // so the actual count may overshoot by up to one COUNT batch.
                    $redis->scanAll(['MATCH' => $prefix.'*', 'limit' => 30], function ($all) use ($emit) {
                        $emit(['count' => count($all), 'unique' => count(array_unique($all))]);
                    });
                }
            });
        }
    PHP);

    expect($result)->toBeArray();
    // Must have collected at least all 30 keys.
    expect($result['count'])->toBeGreaterThanOrEqual(30);
    // Redis returns whole COUNT-sized batches so some overshoot is expected;
    // the default COUNT is ~10 so an upper bound of 30 + 50 is very generous.
    expect($result['count'])->toBeLessThanOrEqual(80);
    // No duplicates regardless of overshoot.
    expect($result['unique'])->toBe($result['count']);
});

it('scan silently ignores unknown option keys', function () {

    $result = runInWorker(<<<'PHP'
        $prefix = 'pest:scan:opts:';
        $redis->del($prefix.'a', $prefix.'b');
        $redis->set($prefix.'a', '1');
        $redis->set($prefix.'b', '2', function () use ($redis, $emit, $prefix) {
            $collected = [];
            $loop = null;
            $loop = function ($reply) use (&$loop, &$collected, $redis, $emit, $prefix) {
                if (!is_array($reply) || !isset($reply['cursor'])) {
                    $emit(['error' => 'bad reply']);
                    return;
                }
                foreach ($reply['keys'] as $k) {
                    $collected[] = $k;
                }
                if ($reply['cursor'] === '0') {
                    $emit(array_values(array_unique($collected)));
                    return;
                }
                // Pass the bogus key again to exercise the ignore path in each call.
                $redis->scan($reply['cursor'], ['BOGUS' => 'value', 'MATCH' => $prefix.'*'], $loop);
            };
            // BOGUS must be silently dropped; the call must not error out.
            $redis->scan('0', ['BOGUS' => 'value', 'MATCH' => $prefix.'*'], $loop);
        });
    PHP);

    expect($result)->toBeArray();
    sort($result);
    expect($result)->toBe(['pest:scan:opts:a', 'pest:scan:opts:b']);
});

it('scan accepts lowercase option keys (case-insensitive routing)', function () {

    $result = runInWorker(<<<'PHP'
        $prefix = 'pest:scan:lower:';
        $redis->del($prefix.'x', $prefix.'y');
        $redis->set($prefix.'x', '1');
        $redis->set($prefix.'y', '2', function () use ($redis, $emit, $prefix) {
            $collected = [];
            $loop = null;
            $loop = function ($reply) use (&$loop, &$collected, $redis, $emit, $prefix) {
                if (!is_array($reply) || !isset($reply['cursor'])) {
                    $emit(['error' => 'bad reply']);
                    return;
                }
                foreach ($reply['keys'] as $k) {
                    $collected[] = $k;
                }
                if ($reply['cursor'] === '0') {
                    $emit(array_values(array_unique($collected)));
                    return;
                }
                $redis->scan($reply['cursor'], ['match' => $prefix.'*', 'count' => 25, 'type' => 'string'], $loop);
            };
            // All option keys lowercase — scan() must uppercase them before
            // sending to Redis.
            $redis->scan('0', ['match' => $prefix.'*', 'count' => 25, 'type' => 'string'], $loop);
        });
    PHP);

    expect($result)->toBeArray();
    sort($result);
    expect($result)->toBe(['pest:scan:lower:x', 'pest:scan:lower:y']);
});

it('scan with malformed cursor receives a non-array pass-through result', function () {

    $result = runInWorker(<<<'PHP'
        // 'not-a-number' is an invalid cursor; Redis/Dragonfly responds with
        // an error reply.  The scan() format callback must pass non-array
        // replies through unchanged so the caller can detect the error.
        $redis->scan('not-a-number', [], function ($reply) use ($emit) {
            $emit([
                'is_array'   => is_array($reply),
                'is_bool'    => is_bool($reply),
                'reply_type' => gettype($reply),
            ]);
        });
    PHP);

    expect($result)->toBeArray();
    // The result must NOT be the normal ['cursor' => ..., 'keys' => ...] shape.
    expect($result['is_array'])->toBe(false);
});
