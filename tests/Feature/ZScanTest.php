<?php

it('zScan returns cursor and member=>score assoc', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:zscan:t1:zset';
        $redis->del($key);
        $pairs = ['m1' => 1, 'm2' => 2, 'm3' => 3, 'm4' => 4, 'm5' => 5];
        $remaining = count($pairs);
        foreach ($pairs as $member => $score) {
            $redis->zAdd($key, $score, $member, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $collected = [];
                    $types = [];
                    $loop = null;
                    $loop = function ($reply) use (&$loop, &$collected, &$types, $redis, $emit, $key) {
                        if (!is_array($reply) || !isset($reply['cursor'])) {
                            $emit(['error' => 'bad reply', 'reply' => $reply]);
                            return;
                        }
                        foreach ($reply['members'] as $m => $s) {
                            $collected[$m] = $s;
                            $types[$m] = gettype($s);
                        }
                        if ($reply['cursor'] === '0') {
                            $emit([
                                'cursor_type' => gettype($reply['cursor']),
                                'cursor_final' => $reply['cursor'],
                                'members' => $collected,
                                'score_types' => $types,
                            ]);
                            return;
                        }
                        $redis->zScan($key, $reply['cursor'], [], $loop);
                    };
                    $redis->zScan($key, '0', [], $loop);
                }
            });
        }
    PHP);

    expect($result)->toBeArray();
    expect($result['cursor_type'])->toBe('string');
    expect($result['cursor_final'])->toBe('0');
    ksort($result['members']);
    expect($result['members'])->toBe([
        'm1' => '1',
        'm2' => '2',
        'm3' => '3',
        'm4' => '4',
        'm5' => '5',
    ]);
    // Scores must stay as strings — float casts lose precision.
    foreach ($result['score_types'] as $type) {
        expect($type)->toBe('string');
    }
});

it('zScan with MATCH filters members by pattern', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:zscan:t2:zset';
        $redis->del($key);
        $pairs = ['a1' => 1, 'a2' => 2, 'b1' => 3];
        $remaining = count($pairs);
        foreach ($pairs as $member => $score) {
            $redis->zAdd($key, $score, $member, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $collected = [];
                    $loop = null;
                    $loop = function ($reply) use (&$loop, &$collected, $redis, $emit, $key) {
                        if (!is_array($reply) || !isset($reply['cursor'])) {
                            $emit(['error' => 'bad reply', 'reply' => $reply]);
                            return;
                        }
                        foreach ($reply['members'] as $m => $s) {
                            $collected[$m] = $s;
                        }
                        if ($reply['cursor'] === '0') {
                            $emit($collected);
                            return;
                        }
                        $redis->zScan($key, $reply['cursor'], ['MATCH' => 'a*'], $loop);
                    };
                    $redis->zScan($key, '0', ['MATCH' => 'a*'], $loop);
                }
            });
        }
    PHP);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('a1');
    expect($result)->toHaveKey('a2');
    expect($result)->not->toHaveKey('b1');
});

it('zScan with COUNT respects the hint', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:zscan:t3:zset';
        $redis->del($key);
        $remaining = 30;
        for ($i = 1; $i <= 30; $i++) {
            $redis->zAdd($key, $i, 'm'.$i, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->zScan($key, '0', ['COUNT' => 10], function ($reply) use ($emit) {
                        $emit([
                            'cursor' => $reply['cursor'] ?? null,
                            'count'  => is_array($reply['members'] ?? null) ? count($reply['members']) : -1,
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

it('zScanAll iterates the full sorted set', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:zscanall:t4:zset';
        $redis->del($key);
        $total = 150;
        $remaining = $total;
        for ($i = 1; $i <= $total; $i++) {
            $redis->zAdd($key, $i, 'm'.$i, function () use (&$remaining, $redis, $emit, $key, $total) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->zScanAll($key, ['COUNT' => 25], function ($all) use ($emit) {
                        $emit([
                            'count'  => is_array($all) ? count($all) : -1,
                            'unique' => is_array($all) ? count(array_unique(array_keys($all))) : -1,
                            'sample_42' => is_array($all) ? ($all['m42'] ?? null) : null,
                            'sample_42_type' => is_array($all) && isset($all['m42']) ? gettype($all['m42']) : null,
                            'sample_99' => is_array($all) ? ($all['m99'] ?? null) : null,
                        ]);
                    });
                }
            });
        }
    PHP, 10);

    expect($result)->toBeArray();
    expect($result['count'])->toBe(150);
    expect($result['unique'])->toBe(150);
    // Scores come back as bulk strings — not floats — to preserve precision.
    expect($result['sample_42'])->toBe('42');
    expect($result['sample_42_type'])->toBe('string');
    expect($result['sample_99'])->toBe('99');
});

it('zScanAll honors the limit option', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:zscanall:t5:zset';
        $redis->del($key);
        $total = 100;
        $remaining = $total;
        for ($i = 1; $i <= $total; $i++) {
            $redis->zAdd($key, $i, 'm'.$i, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->zScanAll($key, ['COUNT' => 25, 'limit' => 30], function ($all) use ($emit) {
                        $emit(['count' => is_array($all) ? count($all) : -1]);
                    });
                }
            });
        }
    PHP, 10);

    expect($result)->toBeArray();
    // limit caps each batch's contribution; the final count may exceed `limit`
    // by up to one COUNT batch because Redis returns whole batches at a time.
    expect($result['count'])->toBeGreaterThanOrEqual(30);
    expect($result['count'])->toBeLessThanOrEqual(55);
});

it('zScan on a missing key returns empty members', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:zscan:missing:xxxxxxxx:zset';
        $redis->del($key);
        $redis->zScan($key, '0', [], function ($reply) use ($emit) {
            $emit([
                'cursor'      => $reply['cursor'] ?? null,
                'cursor_type' => isset($reply['cursor']) ? gettype($reply['cursor']) : null,
                'members'     => $reply['members'] ?? null,
            ]);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['cursor'])->toBe('0');
    expect($result['cursor_type'])->toBe('string');
    expect($result['members'])->toBe([]);
});

it('zScan with malformed cursor passes through the error', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:zscan:malformed:zset';
        $redis->del($key);
        $redis->zAdd($key, 1, 'm1', function () use ($redis, $emit, $key) {
            $redis->zScan($key, 'not-a-number', [], function ($reply) use ($emit) {
                $emit([
                    'is_array'   => is_array($reply),
                    'is_bool'    => is_bool($reply),
                    'reply_type' => gettype($reply),
                ]);
            });
        });
    PHP);

    expect($result)->toBeArray();
    // The result must NOT be the normal ['cursor' => ..., 'members' => ...] shape.
    expect($result['is_array'])->toBe(false);
});

it('zScan preserves score precision as strings', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:zscan:t8:zset';
        $redis->del($key);
        // 1.5 has an exact binary representation, so Redis/Dragonfly returns it
        // verbatim. This proves the client doesn't reformat or float-cast the score.
        $redis->zAdd($key, 1.5, 'precise', function () use ($redis, $emit, $key) {
            $redis->zScan($key, '0', [], function ($reply) use ($emit) {
                $emit([
                    'score'      => $reply['members']['precise'] ?? null,
                    'score_type' => isset($reply['members']['precise']) ? gettype($reply['members']['precise']) : null,
                ]);
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['score_type'])->toBe('string');
    expect($result['score'])->toBe('1.5');
});
