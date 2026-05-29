<?php

/*
|--------------------------------------------------------------------------
| Revolt coroutine mode (Group 8 §8.1 / plan §3D dual-mode gap)
|--------------------------------------------------------------------------
|
| Every other Feature test exercises CALLBACK mode: it runs in
| tests/Support/run-in-worker.php where Revolt is never loaded, so
| Client::queueCommand()'s `class_exists(EventLoop::class, false)` check is
| false and commands are fire-and-callback.
|
| These tests exercise the second, previously-untested mode. They run in
| tests/Support/run-in-worker-coroutine.php, which boots Workerman on the
| Revolt-backed `Workerman\Events\Fiber` driver. Under that driver:
|   - `Revolt\EventLoop` is loaded (the Fiber driver `use`s it), so the
|     `class_exists(..., false)` coroutine check is TRUE; and
|   - onWorkerStart runs inside a real \Fiber, so a command issued WITHOUT a
|     callback suspends that fiber via EventLoop::getSuspension() and is
|     resumed by onMessage when the reply lands — i.e. the call RETURNS its
|     reply synchronously.
|
| This covers: queueCommand()'s `$need_suspend` branch + suspenstion() +
| the onMessage resume, the four *ScanAll coroutine loops, and the
| subscribe/monitor-lock guard.
|
| Coroutine mode is a CLIENT-side concern (suspend/resume the calling
| fiber), engine-independent, so these run identically on Dragonfly and
| Redis via the Makefile targets.
|
| Keys use a pest:g8:coro: prefix to avoid colliding with workers that
| share db0.
*/

it('returns set/get/incr/del replies synchronously with no callback', function () {

    $result = runInCoroutineWorker(<<<'PHP'
        // No callbacks anywhere: each call suspends the fiber and returns its
        // reply directly. Straight-line, synchronous-looking code.
        $del0   = $redis->del('pest:g8:coro:1:k', 'pest:g8:coro:1:n');
        $set    = $redis->set('pest:g8:coro:1:k', 'hello');
        $get    = $redis->get('pest:g8:coro:1:k');
        $incr1  = $redis->incr('pest:g8:coro:1:n');
        $incr2  = $redis->incr('pest:g8:coro:1:n');
        $delK   = $redis->del('pest:g8:coro:1:k');
        $getAft = $redis->get('pest:g8:coro:1:k');
        $emit([
            'set'        => $set,
            'get'        => $get,
            'incr1'      => $incr1,
            'incr2'      => $incr2,
            'del'        => $delK,
            'get_after'  => $getAft,
        ]);
    PHP);

    // SET's "+OK" status reply is normalised to boolean true by the client.
    expect($result['set'])->toBeTrue();
    expect($result['get'])->toBe('hello');
    expect($result['incr1'])->toBe(1);
    expect($result['incr2'])->toBe(2);
    expect($result['del'])->toBe(1);
    // Deleted key reads back as null (callback-less coroutine return).
    expect($result['get_after'])->toBeNull();
});

it('scanAll returns the full key array synchronously in coroutine mode', function () {

    $result = runInCoroutineWorker(<<<'PHP'
        // Seed three keys, then aggregate them through the coroutine-mode
        // scanAll loop (no callback -> synchronous cursor walk + return).
        $redis->del('pest:g8:coro:2:a', 'pest:g8:coro:2:b', 'pest:g8:coro:2:c');
        $redis->set('pest:g8:coro:2:a', '1');
        $redis->set('pest:g8:coro:2:b', '1');
        $redis->set('pest:g8:coro:2:c', '1');
        $keys = $redis->scanAll(['match' => 'pest:g8:coro:2:*', 'count' => 100]);
        sort($keys);
        $emit($keys);
    PHP);

    expect($result)->toBe([
        'pest:g8:coro:2:a',
        'pest:g8:coro:2:b',
        'pest:g8:coro:2:c',
    ]);
});

it('hScanAll/sScanAll/zScanAll aggregate synchronously in coroutine mode', function () {

    $result = runInCoroutineWorker(<<<'PHP'
        $redis->del('pest:g8:coro:3:h', 'pest:g8:coro:3:s', 'pest:g8:coro:3:z');
        $redis->hSet('pest:g8:coro:3:h', 'f1', 'v1');
        $redis->hSet('pest:g8:coro:3:h', 'f2', 'v2');
        $redis->sAdd('pest:g8:coro:3:s', 'm1', 'm2');
        $redis->zAdd('pest:g8:coro:3:z', 1, 'z1', 2, 'z2');

        $h = $redis->hScanAll('pest:g8:coro:3:h', ['count' => 100]);
        ksort($h);
        $s = $redis->sScanAll('pest:g8:coro:3:s', ['count' => 100]);
        sort($s);
        $z = $redis->zScanAll('pest:g8:coro:3:z', ['count' => 100]);
        ksort($z);

        $emit(['h' => $h, 's' => $s, 'z' => $z]);
    PHP);

    expect($result['h'])->toBe(['f1' => 'v1', 'f2' => 'v2']);
    expect($result['s'])->toBe(['m1', 'm2']);
    // zScanAll keeps score strings keyed by member.
    expect($result['z'])->toBe(['z1' => '1', 'z2' => '2']);
});

it('throws when a coroutine-mode command is issued while subscribe-locked', function () {

    $result = runInCoroutineWorker(<<<'PHP'
        // Establish the connection first (a coroutine ping suspends until
        // connected) so SUBSCRIBE is actually sent and the _subscribe lock is
        // set synchronously by process(). Then a callback-less ordinary command
        // must throw rather than suspend into an unresumable fiber.
        $redis->ping();
        $redis->subscribe('pest:g8:coro:4:chan', function () {});
        try {
            $redis->get('pest:g8:coro:4:x');
            $emit(['threw' => false, 'message' => null]);
        } catch (\Throwable $e) {
            $emit(['threw' => true, 'class' => get_class($e), 'message' => $e->getMessage()]);
        }
    PHP);

    expect($result['threw'])->toBeTrue();
    expect($result['class'])->toBe(\Workerman\Redis\Exception::class);
    expect($result['message'])->toContain('subscribe/monitor mode');
});
