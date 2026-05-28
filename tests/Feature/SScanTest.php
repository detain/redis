<?php

it('sScan returns cursor and members list', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:sscan:t1:set';
        $redis->del($key);
        $remaining = 5;
        foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $m) {
            $redis->sAdd($key, $m, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $collected = [];
                    $loop = null;
                    $loop = function ($reply) use (&$loop, &$collected, $redis, $emit, $key) {
                        if (!is_array($reply) || !isset($reply['cursor'])) {
                            $emit(['error' => 'bad reply', 'reply' => $reply]);
                            return;
                        }
                        foreach ($reply['members'] as $m) {
                            $collected[] = $m;
                        }
                        if ($reply['cursor'] === '0') {
                            $emit([
                                'cursor_type'  => gettype($reply['cursor']),
                                'cursor_final' => $reply['cursor'],
                                'members'      => array_values(array_unique($collected)),
                            ]);
                            return;
                        }
                        $redis->sScan($key, $reply['cursor'], [], $loop);
                    };
                    $redis->sScan($key, '0', [], $loop);
                }
            });
        }
    PHP);

    expect($result)->toBeArray();
    expect($result['cursor_type'])->toBe('string');
    expect($result['cursor_final'])->toBe('0');
    sort($result['members']);
    expect($result['members'])->toBe(['m1', 'm2', 'm3', 'm4', 'm5']);
});

it('sScan with MATCH filters members', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:sscan:t2:set';
        $redis->del($key);
        $remaining = 3;
        foreach (['a1', 'a2', 'b1'] as $m) {
            $redis->sAdd($key, $m, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $collected = [];
                    $loop = null;
                    $loop = function ($reply) use (&$loop, &$collected, $redis, $emit, $key) {
                        if (!is_array($reply) || !isset($reply['cursor'])) {
                            $emit(['error' => 'bad reply', 'reply' => $reply]);
                            return;
                        }
                        foreach ($reply['members'] as $m) {
                            $collected[$m] = true;
                        }
                        if ($reply['cursor'] === '0') {
                            $emit(array_keys($collected));
                            return;
                        }
                        $redis->sScan($key, $reply['cursor'], ['MATCH' => 'a*'], $loop);
                    };
                    $redis->sScan($key, '0', ['MATCH' => 'a*'], $loop);
                }
            });
        }
    PHP);

    expect($result)->toBeArray();
    sort($result);
    expect($result)->toBe(['a1', 'a2']);
    expect($result)->not->toContain('b1');
});

it('sScan with COUNT respects the hint', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:sscan:t3:set';
        $redis->del($key);
        $remaining = 30;
        for ($i = 1; $i <= 30; $i++) {
            $redis->sAdd($key, 'm'.$i, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->sScan($key, '0', ['COUNT' => 10], function ($reply) use ($emit) {
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
    // COUNT is purely a hint — Dragonfly may return all 30 in one batch.
    // The meaningful assertion is that the call did not error.
    expect($result['count'])->toBeGreaterThanOrEqual(1);
    expect($result['count'])->toBeLessThanOrEqual(30);
});

it('sScanAll iterates the full set', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:sscanall:t4:set';
        $redis->del($key);
        $total = 150;
        $remaining = $total;
        for ($i = 1; $i <= $total; $i++) {
            $redis->sAdd($key, 'm'.$i, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->sScanAll($key, ['COUNT' => 25], function ($all) use ($emit) {
                        $emit([
                            'count'  => is_array($all) ? count($all) : -1,
                            'unique' => is_array($all) ? count(array_unique($all)) : -1,
                        ]);
                    });
                }
            });
        }
    PHP, 10);

    expect($result)->toBeArray();
    expect($result['count'])->toBe(150);
    expect($result['unique'])->toBe(150);
});

it('sScanAll honors the limit option', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:sscanall:t5:set';
        $redis->del($key);
        $total = 100;
        $remaining = $total;
        for ($i = 1; $i <= $total; $i++) {
            $redis->sAdd($key, 'm'.$i, function () use (&$remaining, $redis, $emit, $key) {
                $remaining--;
                if ($remaining === 0) {
                    $redis->sScanAll($key, ['COUNT' => 25, 'limit' => 30], function ($all) use ($emit) {
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

it('sScan on missing key returns empty members', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:sscan:missing:xxxxxxxx:set';
        $redis->del($key);
        $redis->sScan($key, '0', [], function ($reply) use ($emit) {
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

it('sScan with malformed cursor receives false', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:sscan:malformed:set';
        $redis->del($key);
        $redis->sAdd($key, 'm', function () use ($redis, $emit, $key) {
            $redis->sScan($key, 'not-a-number', [], function ($reply) use ($emit) {
                $emit([
                    'is_array'   => is_array($reply),
                    'is_bool'    => is_bool($reply),
                    'reply'      => $reply,
                    'reply_type' => gettype($reply),
                ]);
            });
        });
    PHP);

    expect($result)->toBeArray();
    // Redis/Dragonfly replies with an error string; the format callback passes
    // non-array results through unchanged so the caller gets `false`.
    expect($result['is_array'])->toBe(false);
    expect($result['is_bool'])->toBe(true);
    expect($result['reply'])->toBe(false);
});
