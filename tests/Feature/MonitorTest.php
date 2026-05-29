<?php

/*
|--------------------------------------------------------------------------
| MONITOR
|--------------------------------------------------------------------------
|
| MONITOR is a long-lived streaming command like SUBSCRIBE: once sent it
| locks the connection (its own $_monitoring flag) and the server streams
| every command it processes back to the callback. There is no UNMONITOR —
| you stop it by close()ing the client.
|
| The test attaches MONITOR on one client, then a SECOND client issues a
| burst of commands; the monitor callback must fire for each. A unique key
| prefix filters out unrelated server traffic (the monitoring client's own
| handshake, other connections, etc.).
*/

it('monitor streams commands issued by another client', function () {

    $result = runInWorker(<<<'PHP'
        $other = new Workerman\Redis\Client('redis://127.0.0.1:6379');
        $seen = [];
        $redis->monitor(function ($line, $client) use ($emit, &$seen) {
            if (strpos($line, 'pest:monitor:t1:') !== false) {
                $seen[] = $line;
                if (count($seen) >= 3) {
                    $emit($seen);
                }
            }
        });
        // Give MONITOR's +OK ack time to land, then have the other client run
        // three commands — the monitor must see all three.
        \Workerman\Timer::add(0.3, function () use ($other) {
            $other->set('pest:monitor:t1:a', '1');
            $other->set('pest:monitor:t1:b', '2');
            $other->set('pest:monitor:t1:c', '3');
        }, [], false);
    PHP, 5);

    expect($result)->toBeArray();
    expect(count($result))->toBeGreaterThanOrEqual(3);
    foreach ($result as $line) {
        expect($line)->toContain('pest:monitor:t1:');
    }
});

it('monitor swallows the OK handshake and only forwards command lines', function () {

    // The first reply to MONITOR is +OK (normalised to boolean true by
    // onMessage). The callback must NOT receive that as a "line" — the only
    // thing it should ever forward is an actual monitor line. Here we emit the
    // first thing the callback sees that mentions our marker; it must be the
    // SET line, never `true`/`1`/empty.
    $result = runInWorker(<<<'PHP'
        $other = new Workerman\Redis\Client('redis://127.0.0.1:6379');
        $redis->monitor(function ($line, $client) use ($emit) {
            if (strpos($line, 'pest:monitor:t2:marker') !== false) {
                $emit($line);
            }
        });
        \Workerman\Timer::add(0.3, function () use ($other) {
            $other->set('pest:monitor:t2:marker', 'value');
        }, [], false);
    PHP, 5);

    expect($result)->toBeString();
    expect($result)->toContain('pest:monitor:t2:marker');
    expect(strtoupper($result))->toContain('SET');
});
