<?php

/*
|--------------------------------------------------------------------------
| QUIT command
|--------------------------------------------------------------------------
|
| QUIT asks the server to close the connection. The explicit quit() method
| flips $_quitting on the Client so the onClose handler skips its usual
| 5-second reconnect timer. The reply is +OK, which the existing onMessage
| convention converts to boolean true before delivering to the callback.
*/

it('quit returns true (OK) when accepted by the server', function () {

    $result = runInWorker(<<<'PHP'
        $redis->quit(function ($reply) use ($emit) {
            $emit($reply);
        });
    PHP);

    expect($result)->toBeTrue();
});

it('quit suppresses the auto-reconnect onClose handler', function () {

    // After QUIT, the onClose handler should see $_quitting === true and
    // skip its usual reconnect timer. Wait long enough for the server's
    // FIN to fly and the close handler to run, then peek at the Client's
    // protected $_connection via reflection. If reconnect had fired we'd
    // see a fresh AsyncTcpConnection; if QUIT really stopped the world we
    // see null (closeConnection() nulls it out).
    $result = runInWorker(<<<'PHP'
        $redis->quit(function ($reply, $client) use ($emit) {
            \Workerman\Timer::add(0.5, function () use ($client, $emit) {
                $ref = new \ReflectionClass($client);
                $connProp = $ref->getProperty('_connection');
                $connProp->setAccessible(true);
                $quitProp = $ref->getProperty('_quitting');
                $quitProp->setAccessible(true);
                $emit([
                    'quitting'    => $quitProp->getValue($client),
                    'connIsNull'  => $connProp->getValue($client) === null,
                ]);
            }, [], false);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['quitting'])->toBeTrue();
    expect($result['connIsNull'])->toBeTrue();
});
