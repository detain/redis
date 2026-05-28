<?php

it('rawCommand sends an arbitrary command and returns the reply', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:rawcmd:k1';
        $redis->del($key);
        $redis->rawCommand('SET', $key, 'rc-value', function ($setReply) use ($redis, $emit, $key) {
            $redis->rawCommand('GET', $key, function ($getReply) use ($emit, $setReply) {
                $emit([
                    'set' => $setReply,
                    'get' => $getReply,
                ]);
            });
        });
    PHP);

    expect($result)->toBeArray();
    // SET returns +OK which the client normalizes to true.
    expect($result['set'])->toBe(true);
    expect($result['get'])->toBe('rc-value');
});

it('rawCommand handles a command with no args', function () {

    $result = runInWorker(<<<'PHP'
        $redis->rawCommand('PING', function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    // PING replies with +PONG (a simple string, NOT the +OK->true normalization
    // path), so the client surfaces the literal 'PONG' string.
    expect($result)->toBe('PONG');
});

it('rawCommand supports binary-safe values', function () {

    $result = runInWorker(<<<'PHP'
        $key = 'pest:rawcmd:bin';
        $value = "line1\r\nline2\r\n\x00trailing";
        $redis->del($key);
        $redis->rawCommand('SET', $key, $value, function () use ($redis, $emit, $key, $value) {
            $redis->rawCommand('GET', $key, function ($got) use ($emit, $value) {
                $emit([
                    'match'      => $got === $value,
                    'length'     => is_string($got) ? strlen($got) : -1,
                    'expect_len' => strlen($value),
                ]);
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['match'])->toBe(true);
    expect($result['length'])->toBe($result['expect_len']);
});

it('rawCommand surfaces server errors', function () {

    $result = runInWorker(<<<'PHP'
        // Send a command Redis/Dragonfly will reject with an -ERR reply.
        // The client signals reply errors by handing the callback `false`.
        $redis->rawCommand('THIS_IS_NOT_A_COMMAND', 'arg1', function ($reply, $client) use ($emit) {
            $emit([
                'reply'     => $reply,
                'reply_typ' => gettype($reply),
                'has_error' => $client->error() !== '',
            ]);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['reply'])->toBe(false);
    expect($result['has_error'])->toBe(true);
});

it('rawCommand without args throws InvalidArgumentException', function () {

    $result = runInWorker(<<<'PHP'
        // Passing only a callable means there are no command parts left after
        // the trailing-callback pop. The guard must throw before anything is
        // queued to the wire.
        try {
            $redis->rawCommand(function ($reply) {});
            $emit(['threw' => false]);
        } catch (\InvalidArgumentException $e) {
            $emit([
                'threw'   => true,
                'message' => $e->getMessage(),
            ]);
        }
    PHP);

    expect($result)->toBeArray();
    expect($result['threw'])->toBe(true);
    expect($result['message'])->toContain('rawCommand');
});
