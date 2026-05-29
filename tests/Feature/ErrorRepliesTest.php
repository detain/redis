<?php

/*
|--------------------------------------------------------------------------
| Group 7B: error-reply handling (systematic)
|--------------------------------------------------------------------------
|
| Confirms the decode error path in Client::onMessage end-to-end: a "-ERR ..."
| / "-WRONGTYPE ..." reply (RESP type '-') is delivered to the callback as
| $reply === false, and the wire message is exposed via $client->error()
| (non-empty). The connection is NOT torn down for a '-' error (only a '!'
| protocol error triggers reconnect), so the queue keeps draining.
|
| Error WORDING differs subtly across engines, so assertions check the
| DELIVERY contract (reply === false + error() non-empty + a tolerant keyword
| match) rather than pinning exact text. The four wordings used here were
| verified identical on Dragonfly (6379) and Redis (63790) via redis-cli, so
| no engine skips are required.
|
| Unique prefix per case: pest:g7:err:<n>: (shared db0).
*/

it('WRONGTYPE error is delivered as false with a typed error message', function () {
    $result = runInWorker(<<<'PHP'
        // A list op against a string key triggers WRONGTYPE.
        $redis->set('pest:g7:err:1', 'i-am-a-string', function () use ($redis, $emit) {
            $redis->lPush('pest:g7:err:1', 'x', function ($reply, $client) use ($emit) {
                $emit([
                    'reply'   => $reply,
                    'reply_t' => gettype($reply),
                    'error'   => $client->error(),
                ]);
            });
        });
    PHP);

    expect($result['reply'])->toBe(false);
    expect($result['error'])->not->toBe('');
    // Tolerant: error mentions WRONGTYPE / "wrong kind of value".
    expect(strtolower($result['error']))->toContain('wrongtype');
});

it('unknown command error is delivered as false mentioning the command is unknown', function () {
    $result = runInWorker(<<<'PHP'
        $redis->rawCommand('NOTACOMMAND', 'x', function ($reply, $client) use ($emit) {
            $emit([
                'reply' => $reply,
                'error' => $client->error(),
            ]);
        });
    PHP);

    expect($result['reply'])->toBe(false);
    expect($result['error'])->not->toBe('');
    expect(strtolower($result['error']))->toContain('unknown command');
});

it('wrong-number-of-arguments error is delivered as false', function () {
    $result = runInWorker(<<<'PHP'
        // SET with only a key (no value) is a wrong-arg-count error.
        $redis->rawCommand('SET', 'pest:g7:err:3', function ($reply, $client) use ($emit) {
            $emit([
                'reply' => $reply,
                'error' => $client->error(),
            ]);
        });
    PHP);

    expect($result['reply'])->toBe(false);
    expect($result['error'])->not->toBe('');
    // Tolerant: error mentions the argument count problem.
    expect(strtolower($result['error']))->toContain('wrong number of arguments');
});

it('value-not-an-integer error is delivered as false', function () {
    $result = runInWorker(<<<'PHP'
        // INCR on a non-numeric string is a "not an integer" error.
        $redis->set('pest:g7:err:4', 'not-a-number', function () use ($redis, $emit) {
            $redis->incr('pest:g7:err:4', function ($reply, $client) use ($emit) {
                $emit([
                    'reply' => $reply,
                    'error' => $client->error(),
                ]);
            });
        });
    PHP);

    expect($result['reply'])->toBe(false);
    expect($result['error'])->not->toBe('');
    expect(strtolower($result['error']))->toContain('not an integer');
});

it('syntax error from a bad option is delivered as false', function () {
    $result = runInWorker(<<<'PHP'
        // SET with a bogus trailing option triggers a syntax error.
        $redis->rawCommand('SET', 'pest:g7:err:5', 'v', 'BOGUSOPTION', function ($reply, $client) use ($emit) {
            $emit([
                'reply' => $reply,
                'error' => $client->error(),
            ]);
        });
    PHP);

    expect($result['reply'])->toBe(false);
    expect($result['error'])->not->toBe('');
    expect(strtolower($result['error']))->toContain('syntax');
});

it('error() is cleared after a subsequent successful command', function () {
    $result = runInWorker(<<<'PHP'
        // First force an error, then run a clean command on the SAME connection
        // (a '-' error does not reconnect) and confirm error() is reset to ''.
        $redis->incr('pest:g7:err:6:str', function () use ($redis, $emit) {
            $redis->set('pest:g7:err:6:str', 'nope', function () use ($redis, $emit) {
                $redis->incr('pest:g7:err:6:str', function ($bad, $client) use ($redis, $emit) {
                    $errAfterFail = $client->error();
                    $redis->ping(function ($pong, $client2) use ($emit, $errAfterFail, $bad) {
                        $emit([
                            'bad'           => $bad,
                            'errAfterFail'  => $errAfterFail,
                            'pong'          => $pong,
                            'errAfterOk'    => $client2->error(),
                        ]);
                    });
                });
            });
        });
    PHP);

    expect($result['bad'])->toBe(false);
    expect($result['errAfterFail'])->not->toBe('');
    // PING replies +PONG (Workerman returns the raw 'PONG' string, not true).
    expect($result['pong'])->toBe('PONG');
    expect($result['errAfterOk'])->toBe('');
});
