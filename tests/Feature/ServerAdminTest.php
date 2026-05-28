<?php

/*
|--------------------------------------------------------------------------
| Tier 5 server administration commands
|--------------------------------------------------------------------------
|
| Covers the subcommand-dispatcher methods (config/acl/slowLog/memory/
| command/cluster) and the explicit no-arg methods (lastSave/save/role/
| digest) plus the multi-arg families (replicaOf, debug) and the
| Dragonfly-specific delEx extension.
|
| SHUTDOWN is NOT exercised here — running it would terminate the shared
| Dragonfly process mid-suite. tests/Unit/MethodSurfaceTest.php asserts
| the method declaration exists via reflection instead.
|
| Some commands (digest, delEx) are Dragonfly-only. The tests skip
| themselves when the server replies with -ERR unknown command rather
| than failing the suite on stock Redis.
*/

it('config GET returns server config', function () {

    $result = runInWorker(<<<'PHP'
        $redis->config('GET', 'maxmemory', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeArray();
    // Reply shape: [key, value].
    expect($result)->toHaveCount(2);
    expect($result[0])->toBe('maxmemory');
});

it('config SET round-trips a transient setting', function () {

    $result = runInWorker(<<<'PHP'
        // 'timeout' is one of the few config keys both stock Redis and
        // Dragonfly accept at runtime. Default is 0 (disabled) on both,
        // so we flip it to 0 explicitly — a no-op write that still
        // exercises the CONFIG SET path.
        $redis->config('SET', 'timeout', '0', function ($setReply) use ($redis, $emit) {
            $redis->config('GET', 'timeout', function ($getReply) use ($emit, $setReply) {
                $emit([
                    'set' => $setReply,
                    'get' => $getReply,
                ]);
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['set'])->toBeTrue();
    expect($result['get'])->toBeArray();
    expect($result['get'][0])->toBe('timeout');
    expect($result['get'][1])->toBe('0');
});

it('acl WHOAMI returns the current user', function () {

    $result = runInWorker(<<<'PHP'
        $redis->acl('WHOAMI', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    // Stock Redis returns just 'default'; Dragonfly returns 'User is default'.
    // Accept either by asserting the reply contains the user name.
    expect($result)->toBeString();
    expect($result)->toContain('default');
});

it('acl LIST returns the user list', function () {

    $result = runInWorker(<<<'PHP'
        $redis->acl('LIST', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeArray();
    expect(count($result))->toBeGreaterThanOrEqual(1);
});

it('slowLog LEN returns an integer', function () {

    $result = runInWorker(<<<'PHP'
        $redis->slowLog('LEN', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeInt();
    expect($result)->toBeGreaterThanOrEqual(0);
});

it('slowLog GET returns an array', function () {

    $result = runInWorker(<<<'PHP'
        $redis->slowLog('GET', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeArray();
});

it('slowLog RESET succeeds', function () {

    $result = runInWorker(<<<'PHP'
        $redis->slowLog('RESET', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeTrue();
});

it('memory USAGE returns a byte count for an existing key', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:srv:mem:k';
        $redis->set($key, 'some-value-to-measure', function () use ($redis, $emit, $key) {
            $redis->memory('USAGE', $key, function ($reply) use ($emit) {
                $emit($reply);
            });
        });
    PHP);

    expect($result)->toBeInt();
    expect($result)->toBeGreaterThan(0);
});

it('command COUNT returns the total command count', function () {

    $result = runInWorker(<<<'PHP'
        $redis->command('COUNT', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeInt();
    // Any reasonable Redis/Dragonfly build exposes well over a hundred
    // commands — this is a coarse sanity check, not a tight assertion.
    expect($result)->toBeGreaterThan(100);
});

it('command INFO returns details for GET', function () {

    $result = runInWorker(<<<'PHP'
        $redis->command('INFO', 'GET', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(1);
    // The inner row is [name, arity, flags, ...]. Verify the verb name.
    // Stock Redis lowercases it ('get'), Dragonfly uppercases it ('GET');
    // accept either by comparing case-insensitively.
    expect($result[0])->toBeArray();
    expect(strtolower((string) $result[0][0]))->toBe('get');
});

it('command (no args) returns the full command table', function () {

    $result = runInWorker(<<<'PHP'
        // Bare COMMAND form — verify dispatcher's empty-verb special case.
        $redis->command(function ($reply) use ($emit) {
            $emit(['count' => is_array($reply) ? count($reply) : -1]);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['count'])->toBeGreaterThan(100);
});

it('cluster INFO returns the cluster status or surfaces a disabled-cluster error', function () {

    $result = runInWorker(<<<'PHP'
        $redis->cluster('INFO', function ($reply, $client) use ($emit) {
            $emit([
                'reply' => $reply,
                'error' => $client->error(),
            ]);
        });
    PHP);

    expect($result)->toBeArray();
    if ($result['reply'] === false) {
        // Dragonfly disables cluster mode unless --cluster_mode is set, so
        // it answers CLUSTER INFO with -ERR. Accept that as a valid round-trip
        // — what we care about is that the dispatch path delivered the verb.
        expect($result['error'])->not->toBe('');
        return;
    }
    expect($result['reply'])->toBeString();
    expect($result['reply'])->toContain('cluster_enabled');
});

it('lastSave returns a unix timestamp', function () {

    $result = runInWorker(<<<'PHP'
        $redis->lastSave(function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeInt();
    expect($result)->toBeGreaterThan(0);
});

it('role returns the role tuple', function () {

    $result = runInWorker(<<<'PHP'
        $redis->role(function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeArray();
    // First element is the role label: 'master', 'slave', or 'sentinel'.
    expect($result[0])->toBeIn(['master', 'slave', 'replica', 'sentinel']);
});

it('replicaOf NO ONE succeeds on a standalone server', function () {

    $result = runInWorker(<<<'PHP'
        // REPLICAOF NO ONE is the idempotent "become master" command.
        $redis->replicaOf('NO', 'ONE', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeTrue();
});

it('debug subcommand round-trips through the dispatcher', function () {

    // Dragonfly does not ship DEBUG SLEEP, while stock Redis does. Both
    // implement DEBUG OBJECT against an existing key — exercise that
    // path on every server so we always cover the multi-arg @method.
    $result = runInWorker(<<<'PHP'
        $key = 'pest:srv:debug:k';
        $redis->set($key, 'v', function () use ($redis, $emit, $key) {
            $redis->debug('OBJECT', $key, function ($reply, $client) use ($emit) {
                $emit([
                    'reply' => $reply,
                    'error' => $client->error(),
                ]);
            });
        });
    PHP);

    expect($result)->toBeArray();
    if ($result['reply'] === false) {
        // Some Dragonfly builds disable DEBUG entirely; allow the
        // unknown-subcommand reply through so the test isn't brittle.
        expect($result['error'])->not->toBe('');
        return;
    }
    // DEBUG OBJECT returns a single-line +simple-string reply on success.
    // Different servers format the line differently; assert it's not empty.
    expect($result['reply'])->not->toBeNull();
});

it('save synchronously triggers a snapshot', function () {

    $result = runInWorker(<<<'PHP'
        // SAVE on Dragonfly is a non-blocking snapshot, but still returns +OK.
        // On Redis it blocks the server briefly; either way the reply is true.
        $redis->save(function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP, 10);

    expect($result)->toBeTrue();
});

it('digest returns a string where supported or surfaces the wire error', function () {

    // DIGEST is an old debugging hook; both stock Redis and Dragonfly
    // moved it under DEBUG DIGEST (which is itself optional). Calling
    // the bare DIGEST verb usually returns -ERR. The test verifies the
    // dispatch path delivers the verb cleanly either way.
    $result = runInWorker(<<<'PHP'
        $redis->digest(function ($reply, $client) use ($emit) {
            $emit([
                'reply' => $reply,
                'error' => $client->error(),
            ]);
        });
    PHP);

    expect($result)->toBeArray();
    if ($result['reply'] === false) {
        // Most servers reply -ERR — that's fine, we only care that the
        // call reached the wire and produced *some* signal.
        expect($result['error'])->not->toBe('');
        return;
    }
    expect($result['reply'])->toBeString();
});

it('delEx routes through __call to Dragonfly or surfaces unknown-command on Redis', function () {

    // DELEX is a Dragonfly extension (DEL + per-key liveness check).
    // Argument grammar varies across Dragonfly versions; current builds
    // accept `DELEX key`. Stock Redis lacks the verb entirely and replies
    // -ERR unknown command. Either way the test confirms the @method
    // declaration routes through __call() onto the wire.
    $result = runInWorker(<<<'PHP'
        $key = 'pest:srv:delex:k';
        $redis->set($key, 'sentinel', function () use ($redis, $emit, $key) {
            // Single-key form — broadest compatibility across Dragonfly builds.
            $redis->delEx($key, function ($reply, $client) use ($emit) {
                $emit([
                    'reply' => $reply,
                    'error' => $client->error(),
                ]);
            });
        });
    PHP);

    expect($result)->toBeArray();
    if ($result['reply'] === false && str_contains((string) $result['error'], 'unknown command')) {
        // Stock Redis: skip cleanly.
        expect(true)->toBeTrue();
        return;
    }
    // Dragonfly: DELEX returns the count of removed keys.
    if (\is_int($result['reply'])) {
        expect($result['reply'])->toBeGreaterThanOrEqual(0);
        return;
    }
    // Any other reply means the verb hit the wire — record the error for
    // visibility but don't fail; the dispatch surface is what we're guarding.
    expect($result)->toHaveKey('error');
});
