<?php

/*
|--------------------------------------------------------------------------
| Strings / Keys / Connection extras
|--------------------------------------------------------------------------
|
| Covers commands that route through Client::__call() because each one has
| more than a single wire arg (so the count($args) > 1 branch picks up the
| trailing callback). No explicit method — only @method declarations on
| the class docblock and these integration tests confirming they work end
| to end against a live server.
|
| GETDEL, GETEX, SUBSTR        (Strings)
| COPY, TOUCH, EXPIRETIME,
| PEXPIRETIME                  (Keys)
| ECHO, HELLO                  (Connection / server)
|
| All keys use the pest:extra: prefix to avoid collisions with other
| feature tests.
*/

it('getDel returns the value and deletes the key', function () {

    $result = runInWorker(<<<'PHP'
        $redis->set('pest:extra:getdel', 'v', function () use ($redis, $emit) {
            $redis->getDel('pest:extra:getdel', function ($value) use ($redis, $emit) {
                $redis->get('pest:extra:getdel', function ($after) use ($value, $emit) {
                    $emit(['value' => $value, 'after' => $after]);
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['value'])->toBe('v');
    expect($result['after'])->toBeNull();
});

it('getEx without options returns the value without changing TTL', function () {

    $result = runInWorker(<<<'PHP'
        $redis->set('pest:extra:getex:plain', 'v', function () use ($redis, $emit) {
            $redis->getEx('pest:extra:getex:plain', function ($value) use ($emit) {
                $emit($value);
            });
        });
    PHP);

    expect($result)->toBe('v');
});

it('getEx EX sets a TTL', function () {

    $result = runInWorker(<<<'PHP'
        $redis->set('pest:extra:getex:ttl', 'v', function () use ($redis, $emit) {
            $redis->getEx('pest:extra:getex:ttl', ['EX', 60], function ($value) use ($redis, $emit) {
                $redis->ttl('pest:extra:getex:ttl', function ($ttl) use ($value, $emit) {
                    $emit(['value' => $value, 'ttl' => $ttl]);
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['value'])->toBe('v');
    expect($result['ttl'])->toBeGreaterThanOrEqual(1);
    expect($result['ttl'])->toBeLessThanOrEqual(60);
});

it('substr returns a slice of the value', function () {

    $result = runInWorker(<<<'PHP'
        $redis->set('pest:extra:substr', 'hello world', function () use ($redis, $emit) {
            $redis->substr('pest:extra:substr', 0, 4, function ($slice) use ($emit) {
                $emit($slice);
            });
        });
    PHP);

    expect($result)->toBe('hello');
});

it('copy duplicates a key to a new destination', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:extra:copy:src', 'pest:extra:copy:dst', function () use ($redis, $emit) {
            $redis->set('pest:extra:copy:src', 'value', function () use ($redis, $emit) {
                $redis->copy('pest:extra:copy:src', 'pest:extra:copy:dst', function ($ok) use ($redis, $emit) {
                    $redis->get('pest:extra:copy:dst', function ($dst) use ($ok, $emit) {
                        $emit(['ok' => $ok, 'dst' => $dst]);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['ok'])->toBe(1);
    expect($result['dst'])->toBe('value');
});

it('copy with REPLACE overwrites existing dst', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:extra:copy:rsrc', 'pest:extra:copy:rdst', function () use ($redis, $emit) {
            $redis->set('pest:extra:copy:rsrc', 'v1', function () use ($redis, $emit) {
                $redis->set('pest:extra:copy:rdst', 'v2', function () use ($redis, $emit) {
                    // Without REPLACE: copying onto an existing key returns 0.
                    $redis->copy('pest:extra:copy:rsrc', 'pest:extra:copy:rdst', function ($noReplace) use ($redis, $emit) {
                        // With REPLACE: overwrites and returns 1.
                        $redis->copy('pest:extra:copy:rsrc', 'pest:extra:copy:rdst', ['REPLACE'], function ($withReplace) use ($redis, $noReplace, $emit) {
                            $redis->get('pest:extra:copy:rdst', function ($dst) use ($noReplace, $withReplace, $emit) {
                                $emit([
                                    'noReplace'   => $noReplace,
                                    'withReplace' => $withReplace,
                                    'dst'         => $dst,
                                ]);
                            });
                        });
                    });
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['noReplace'])->toBe(0);
    expect($result['withReplace'])->toBe(1);
    expect($result['dst'])->toBe('v1');
});

it('touch returns count of existing keys', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:extra:touch:k1', 'pest:extra:touch:k2', 'pest:extra:touch:missing', function () use ($redis, $emit) {
            $redis->set('pest:extra:touch:k1', 'v', function () use ($redis, $emit) {
                $redis->set('pest:extra:touch:k2', 'v', function () use ($redis, $emit) {
                    $redis->touch('pest:extra:touch:k1', 'pest:extra:touch:k2', 'pest:extra:touch:missing', function ($n) use ($emit) {
                        $emit($n);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(2);
});

it('expireTime returns absolute unix timestamp', function () {

    $future = time() + 3600;

    $result = runInWorker(<<<PHP
        \$redis->set('pest:extra:expiretime', 'v', function () use (\$redis, \$emit) {
            \$redis->expireAt('pest:extra:expiretime', {$future}, function () use (\$redis, \$emit) {
                \$redis->expireTime('pest:extra:expiretime', function (\$ts) use (\$emit) {
                    \$emit(\$ts);
                });
            });
        });
    PHP);

    expect($result)->toBe($future);
});

it('pExpireTime returns absolute millisecond timestamp', function () {

    $futureMs = (time() + 3600) * 1000;

    $result = runInWorker(<<<PHP
        \$redis->set('pest:extra:pexpiretime', 'v', function () use (\$redis, \$emit) {
            \$redis->pexpireAt('pest:extra:pexpiretime', {$futureMs}, function () use (\$redis, \$emit) {
                \$redis->pExpireTime('pest:extra:pexpiretime', function (\$ts) use (\$emit) {
                    \$emit(\$ts);
                });
            });
        });
    PHP);

    expect($result)->toBe($futureMs);
});

it('echo returns the same message it was sent', function () {

    $result = runInWorker(<<<'PHP'
        $redis->echo('pest-echo-msg', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBe('pest-echo-msg');
});

it('hello returns server protocol info', function () {

    // Some Dragonfly builds return an empty array for HELLO with no args.
    // We just want to confirm the call completes without erroring and the
    // reply is array-shaped (or empty array, treated as success).
    $result = runInWorker(<<<'PHP'
        $redis->hello(function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeArray();
});
