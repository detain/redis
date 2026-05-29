<?php

use Workerman\Redis\Client;

/*
|--------------------------------------------------------------------------
| handleUnsubscribeAck() lock-clearing bookkeeping (pure logic, no server)
|--------------------------------------------------------------------------
|
| handleUnsubscribeAck() is a protected method with no socket/event-loop
| dependency: given an UNSUBSCRIBE ack array [kind, channel, remainingCount]
| it decides whether to keep the subscribe lock (remaining > 0) or tear it
| down — clearing $_subscribe, dropping a pinned SUBSCRIBE entry from the
| queue head, and firing+clearing any registered unsubscribe completion
| callbacks.
|
| Driven entirely via reflection: build a no-constructor Client, seed the
| relevant protected props, invoke the method, assert the post-state.
*/

function ackClient(): Client
{
    return (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
}

function ackSet(Client $client, string $prop, $value): void
{
    $p = (new ReflectionClass(Client::class))->getProperty($prop);
    $p->setAccessible(true);
    $p->setValue($client, $value);
}

function ackGet(Client $client, string $prop)
{
    $p = (new ReflectionClass(Client::class))->getProperty($prop);
    $p->setAccessible(true);

    return $p->getValue($client);
}

function ackInvoke(Client $client, $result): void
{
    $m = (new ReflectionClass(Client::class))->getMethod('handleUnsubscribeAck');
    $m->setAccessible(true);
    $m->invoke($client, $result);
}

it('keeps the lock and holds callbacks when channels still remain (remaining > 0)', function () {
    $client = ackClient();
    ackSet($client, '_subscribe', true);
    $fired = false;
    ackSet($client, '_unsubscribeCallbacks', [function () use (&$fired) {
        $fired = true;
    }]);
    ackSet($client, '_queue', [[['SUBSCRIBE', 'a'], time(), null]]);

    // Ack: unsubscribed from one channel, 1 still remaining.
    ackInvoke($client, ['unsubscribe', 'a', 1]);

    expect(ackGet($client, '_subscribe'))->toBeTrue();   // lock held
    expect($fired)->toBeFalse();                          // callbacks NOT fired
    expect(ackGet($client, '_unsubscribeCallbacks'))->toHaveCount(1); // still queued
    // Pinned SUBSCRIBE entry untouched.
    expect(ackGet($client, '_queue'))->toHaveCount(1);
});

it('clears the lock, drops the pinned SUBSCRIBE entry, and fires callbacks when remaining is 0', function () {
    $client = ackClient();
    ackSet($client, '_subscribe', true);
    $calls = [];
    ackSet($client, '_unsubscribeCallbacks', [
        function ($ok, $c) use (&$calls) {
            $calls[] = $ok;
        },
        function ($ok, $c) use (&$calls) {
            $calls[] = $ok;
        },
    ]);
    ackSet($client, '_queue', [[['SUBSCRIBE', 'a'], time(), null]]);

    ackInvoke($client, ['unsubscribe', 'a', 0]);

    expect(ackGet($client, '_subscribe'))->toBeFalse();          // lock cleared
    expect(ackGet($client, '_queue'))->toBe([]);                 // pinned entry dropped
    expect($calls)->toBe([true, true]);                          // both callbacks fired with (true, $this)
    expect(ackGet($client, '_unsubscribeCallbacks'))->toBe([]);  // callbacks cleared
});

it('treats a missing remaining-count element as 0 (fail-safe teardown)', function () {
    // Malformed ack with no [2] element: the safe mode is to unlock, not to
    // stay locked forever.
    $client = ackClient();
    ackSet($client, '_subscribe', true);
    ackSet($client, '_unsubscribeCallbacks', []);
    ackSet($client, '_queue', [[['PSUBSCRIBE', 'p*'], time(), null]]);

    ackInvoke($client, ['unsubscribe']);   // no count element

    expect(ackGet($client, '_subscribe'))->toBeFalse();
    expect(ackGet($client, '_queue'))->toBe([]);   // PSUBSCRIBE head also dropped
});

it('does not drop a non-stream queue head when tearing down', function () {
    // If the head isn't a SUBSCRIBE/PSUBSCRIBE/SSUBSCRIBE entry it must survive.
    $client = ackClient();
    ackSet($client, '_subscribe', true);
    ackSet($client, '_unsubscribeCallbacks', []);
    ackSet($client, '_queue', [[['GET', 'somekey'], time(), null]]);

    ackInvoke($client, ['unsubscribe', 'a', 0]);

    expect(ackGet($client, '_subscribe'))->toBeFalse();
    // The GET entry is NOT a stream head, so it stays in the queue.
    $queue = ackGet($client, '_queue');
    expect($queue)->toHaveCount(1);
    expect($queue[array_key_first($queue)][0])->toBe(['GET', 'somekey']);
});

it('handles an empty queue gracefully during teardown', function () {
    $client = ackClient();
    ackSet($client, '_subscribe', true);
    ackSet($client, '_unsubscribeCallbacks', []);
    ackSet($client, '_queue', []);

    ackInvoke($client, ['unsubscribe', 'a', 0]);

    expect(ackGet($client, '_subscribe'))->toBeFalse();
    expect(ackGet($client, '_queue'))->toBe([]);
});
