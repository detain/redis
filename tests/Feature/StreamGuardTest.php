<?php

/*
|--------------------------------------------------------------------------
| Single-stream guard
|--------------------------------------------------------------------------
|
| This client pins ONE streaming entry (SUBSCRIBE / PSUBSCRIBE / SSUBSCRIBE
| / MONITOR) at the head of the queue and routes every incoming message to
| that entry's callback. A second subscribe while one is active or merely
| pending can't be honoured — process() is locked, so the frame would never
| reach the wire. Previously it was dropped silently; it now throws so the
| misuse is visible. The guard inspects both the live flags AND the queue,
| so it fires even on back-to-back calls before the first frame is sent
| (when the flags are still false).
*/

it('a second subscribe on the same client throws instead of silently dropping', function () {

    $result = runInWorker(<<<'PHP'
        $redis->subscribe(['pest:streamguard:t1:a'], function () {});
        try {
            // Second subscribe — the first is still pending in the queue, so
            // the flags are false but streamActiveOrPending() still catches it.
            $redis->subscribe(['pest:streamguard:t1:b'], function () {});
            $emit('no-throw');
        } catch (\Workerman\Redis\Exception $e) {
            $emit('threw');
        }
    PHP, 5);

    expect($result)->toBe('threw');
});

it('mixing pSubscribe after subscribe on one client throws', function () {

    $result = runInWorker(<<<'PHP'
        $redis->subscribe(['pest:streamguard:t2:a'], function () {});
        try {
            $redis->pSubscribe(['pest:streamguard:t2:*'], function () {});
            $emit('no-throw');
        } catch (\Workerman\Redis\Exception $e) {
            $emit('threw');
        }
    PHP, 5);

    expect($result)->toBe('threw');
});

it('a single subscribe is unaffected — an ordinary command still drains after unsubscribe', function () {

    // Sanity check that the guard does not block the normal single-stream flow.
    $result = runInWorker(<<<'PHP'
        $redis->subscribe(['pest:streamguard:t3:chan'], function () {});
        \Workerman\Timer::add(0.3, function () use ($redis, $emit) {
            $redis->unsubscribe();
            $redis->ping(function ($pong) use ($emit) {
                $emit($pong);
            });
        }, [], false);
    PHP, 5);

    expect($result)->toBe('PONG');
});
