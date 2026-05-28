<?php

it('hScan returns cursor and assoc fields', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hscan:t1:hash';
        $redis->del($key);
        $remaining = 5;
        foreach (['f1' => 'v1', 'f2' => 'v2', 'f3' => 'v3', 'f4' => 'v4', 'f5' => 'v5'] as $f => $v) {
            $redis->hSet($key, $f, $v, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $collected = [];
                    $loop = null;
                    $loop = function ($reply) use (&$loop, &$collected, $redis, $emit, $key) {
                        if (!is_array($reply) || !isset($reply['cursor'])) {
                            $emit(['error' => 'bad reply', 'reply' => $reply]);
                            return;
                        }
                        foreach ($reply['fields'] as $f => $v) {
                            $collected[$f] = $v;
                        }
                        if ($reply['cursor'] === '0') {
                            $emit([
                                'cursor_type' => gettype($reply['cursor']),
                                'cursor_final' => $reply['cursor'],
                                'fields' => $collected,
                            ]);
                            return;
                        }
                        $redis->hScan($key, $reply['cursor'], [], $loop);
                    };
                    $redis->hScan($key, '0', [], $loop);
                }
            });
        }
    PHP);

    expect($result)->toBeArray();
    expect($result['cursor_type'])->toBe('string');
    expect($result['cursor_final'])->toBe('0');
    ksort($result['fields']);
    expect($result['fields'])->toBe([
        'f1' => 'v1',
        'f2' => 'v2',
        'f3' => 'v3',
        'f4' => 'v4',
        'f5' => 'v5',
    ]);
});

it('hScan with MATCH filters fields by pattern', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hscan:t2:hash';
        $redis->del($key);
        $remaining = 3;
        foreach (['a1' => '1', 'a2' => '2', 'b1' => '3'] as $f => $v) {
            $redis->hSet($key, $f, $v, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $collected = [];
                    $loop = null;
                    $loop = function ($reply) use (&$loop, &$collected, $redis, $emit, $key) {
                        if (!is_array($reply) || !isset($reply['cursor'])) {
                            $emit(['error' => 'bad reply', 'reply' => $reply]);
                            return;
                        }
                        foreach ($reply['fields'] as $f => $v) {
                            $collected[$f] = $v;
                        }
                        if ($reply['cursor'] === '0') {
                            $emit($collected);
                            return;
                        }
                        $redis->hScan($key, $reply['cursor'], ['MATCH' => 'a*'], $loop);
                    };
                    $redis->hScan($key, '0', ['MATCH' => 'a*'], $loop);
                }
            });
        }
    PHP);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('a1');
    expect($result)->toHaveKey('a2');
    expect($result)->not->toHaveKey('b1');
});

it('hScan with COUNT respects the hint', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hscan:t3:hash';
        $redis->del($key);
        $remaining = 30;
        for ($i = 1; $i <= 30; $i++) {
            $redis->hSet($key, 'f'.$i, (string)$i, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->hScan($key, '0', ['COUNT' => 10], function ($reply) use ($emit) {
                        $emit([
                            'cursor' => $reply['cursor'] ?? null,
                            'count'  => is_array($reply['fields'] ?? null) ? count($reply['fields']) : -1,
                        ]);
                    });
                }
            });
        }
    PHP);

    expect($result)->toBeArray();
    // COUNT is only a hint. Dragonfly may return all 30 in one batch.
    // The meaningful assertion is that the call did not error.
    expect($result['count'])->toBeGreaterThanOrEqual(1);
    expect($result['count'])->toBeLessThanOrEqual(30);
});

it('hScanAll iterates the full hash', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hscanall:t4:hash';
        $redis->del($key);
        $total = 150;
        $remaining = $total;
        for ($i = 1; $i <= $total; $i++) {
            $redis->hSet($key, 'field'.$i, 'value'.$i, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->hScanAll($key, ['COUNT' => 25], function ($all) use ($emit) {
                        $emit([
                            'count'  => is_array($all) ? count($all) : -1,
                            'unique' => is_array($all) ? count(array_unique(array_keys($all))) : -1,
                            'sample' => is_array($all) ? ($all['field42'] ?? null) : null,
                        ]);
                    });
                }
            });
        }
    PHP, 10);

    expect($result)->toBeArray();
    expect($result['count'])->toBe(150);
    expect($result['unique'])->toBe(150);
    expect($result['sample'])->toBe('value42');
});

it('hScanAll honors the limit option', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hscanall:t5:hash';
        $redis->del($key);
        $total = 100;
        $remaining = $total;
        for ($i = 1; $i <= $total; $i++) {
            $redis->hSet($key, 'field'.$i, (string)$i, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->hScanAll($key, ['COUNT' => 25, 'limit' => 30], function ($all) use ($emit) {
                        $emit(['count' => is_array($all) ? count($all) : -1]);
                    });
                }
            });
        }
    PHP, 10);

    expect($result)->toBeArray();
    // limit caps each batch's contribution; the final count may exceed `limit`
    // by up to one COUNT batch because Redis returns whole batches at a time.
    expect($result['count'])->toBeLessThanOrEqual(55);
    expect($result['count'])->toBeGreaterThan(0);
});

it('hScan on a missing key returns empty fields', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hscan:missing:xxxxxxxx:hash';
        $redis->del($key);
        $redis->hScan($key, '0', [], function ($reply) use ($emit) {
            $emit([
                'cursor'      => $reply['cursor'] ?? null,
                'cursor_type' => isset($reply['cursor']) ? gettype($reply['cursor']) : null,
                'fields'      => $reply['fields'] ?? null,
            ]);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['cursor'])->toBe('0');
    expect($result['cursor_type'])->toBe('string');
    expect($result['fields'])->toBe([]);
});

it('hScan with malformed cursor passes through the error', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:hscan:malformed:hash';
        $redis->del($key);
        $redis->hSet($key, 'f', 'v', function () use ($redis, $emit, $key) {
            $redis->hScan($key, 'not-a-number', [], function ($reply) use ($emit) {
                $emit([
                    'is_array'   => is_array($reply),
                    'is_bool'    => is_bool($reply),
                    'reply_type' => gettype($reply),
                ]);
            });
        });
    PHP);

    expect($result)->toBeArray();
    // The result must NOT be the normal ['cursor' => ..., 'fields' => ...] shape.
    expect($result['is_array'])->toBe(false);
});
