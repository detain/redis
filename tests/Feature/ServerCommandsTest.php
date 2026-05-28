<?php

/*
|--------------------------------------------------------------------------
| No-arg server / connection commands
|--------------------------------------------------------------------------
|
| Covers ping(), info(), dbSize(), time(), flushDb(), flushAll(). These
| sit on the wrong side of __call()'s count($args) > 1 guard, so each one
| has an explicit method. Tests verify the trailing-callback pattern
| actually invokes the callback with the parsed reply (not garbage and
| not a hang).
|
| flush tests SELECT a high DB index (14) inside the worker snippet so
| we never wipe data another test is using.
*/

it('ping returns PONG', function () {

    $result = runInWorker(<<<'PHP'
        $redis->ping(function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBe('PONG');
});

it('info returns a non-empty string', function () {

    $result = runInWorker(<<<'PHP'
        $redis->info(function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeString();
    expect(strlen($result))->toBeGreaterThan(50);
    // Redis emits 'redis_version', Dragonfly emits 'dragonfly_version'.
    // Either one signals we got a real INFO bulk back (and not a garbled
    // reply from the closure-on-wire bug).
    $hasVersion = str_contains($result, 'redis_version') || str_contains($result, 'dragonfly_version');
    expect($hasVersion)->toBe(true);
});

it('info with section filter returns scoped output', function () {

    $result = runInWorker(<<<'PHP'
        $redis->info('server', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeString();
    // Both servers expose a version key under the 'server' section.
    expect($result)->toContain('_version');
});

it('dbSize returns an integer', function () {

    $result = runInWorker(<<<'PHP'
        // Land on a dedicated DB so other tests' fixtures don't skew
        // the count we're about to assert against.
        $redis->rawCommand('SELECT', 14, function () use ($redis, $emit) {
            $redis->rawCommand('FLUSHDB', function () use ($redis, $emit) {
                $redis->set('pest:srv:dbsize:a', '1');
                $redis->set('pest:srv:dbsize:b', '2');
                $redis->set('pest:srv:dbsize:c', '3', function () use ($redis, $emit) {
                    $redis->dbSize(function ($size) use ($emit) {
                        $emit($size);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeInt();
    expect($result)->toBeGreaterThanOrEqual(3);
});

it('time returns a two-element array of digit strings', function () {

    $result = runInWorker(<<<'PHP'
        $redis->time(function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(2);
    // Both fields arrive as bulk strings of digits.
    expect(ctype_digit((string) $result[0]))->toBe(true);
    expect(ctype_digit((string) $result[1]))->toBe(true);
});

it('flushDb empties the current database', function () {

    $result = runInWorker(<<<'PHP'
        // Dedicated DB index so we don't nuke other tests' data.
        $redis->rawCommand('SELECT', 14, function () use ($redis, $emit) {
            $redis->set('pest:srv:flush:k', '1', function () use ($redis, $emit) {
                $redis->flushDb(function () use ($redis, $emit) {
                    $redis->dbSize(function ($size) use ($emit) {
                        $emit($size);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(0);
});

it('flushDb ASYNC also empties the database', function () {

    $result = runInWorker(<<<'PHP'
        $redis->rawCommand('SELECT', 14, function () use ($redis, $emit) {
            $redis->set('pest:srv:flush:async', '1', function () use ($redis, $emit) {
                $redis->flushDb(true, function () use ($redis, $emit) {
                    $redis->dbSize(function ($size) use ($emit) {
                        $emit($size);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(0);
});

it('flushAll empties all databases', function () {

    $result = runInWorker(<<<'PHP'
        $redis->rawCommand('SELECT', 14, function () use ($redis, $emit) {
            $redis->set('pest:srv:flushall:k', '1', function () use ($redis, $emit) {
                $redis->flushAll(function () use ($redis, $emit) {
                    $redis->dbSize(function ($size) use ($emit) {
                        $emit($size);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(0);
});
