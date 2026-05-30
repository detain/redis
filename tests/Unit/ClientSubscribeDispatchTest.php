<?php

use Workerman\Redis\Client;

/*
|--------------------------------------------------------------------------
| Group 9 close-out — subscribe-family dispatch arms (pure logic, no server)
|--------------------------------------------------------------------------
|
| subscribe() / pSubscribe() / sSubscribe() each wrap the user callback in a
| `$new_cb` closure that switches on the streamed reply's response-type token.
| Most arms (message / pmessage / smessage and the subscribe-ack) are covered
| by the Feature pub/sub suite, but three arms are awkward to drive over a
| real socket:
|
|   1. the `if (!$result) { echo $this->error(); return; }` guard — fires when
|      onMessage hands the wrapper a falsey result (an error frame);
|   2. the unsubscribe-family ack arm, which delegates to handleUnsubscribeAck()
|      (its own bookkeeping is unit-tested in ClientUnsubscribeAckTest); and
|   3. the `default:` unknown-response-type arm, a diagnostic sink the server
|      never legitimately triggers.
|
| All three are reachable in-process: queue the subscribe, pull the stored
| wrapper out of the queue via reflection, and invoke it with a crafted
| reply. process() is inert ($_connection is null), so queueing is a pure
| append. The diagnostic arms write to stdout via echo; we wrap the invocation
| in an output buffer and assert on the captured text so the arm is both
| executed and observed without leaking into the test output.
*/

function subClient(): Client
{
    return (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
}

/**
 * Pull the wrapper callback ($new_cb) that a subscribe-family method stored as
 * the queued entry's callback (entry index [2]).
 *
 * @return callable
 */
function subWrapper(Client $client): callable
{
    $prop = (new ReflectionClass(Client::class))->getProperty('_queue');
    $prop->setAccessible(true);
    /** @var array<int, mixed> $queue */
    $queue = $prop->getValue($client);
    $entry = $queue[array_key_first($queue)];
    $cb = $entry[2];
    assert(is_callable($cb));

    return $cb;
}

function subProp(Client $client, string $name)
{
    $prop = (new ReflectionClass(Client::class))->getProperty($name);
    $prop->setAccessible(true);

    return $prop->getValue($client);
}

function subSet(Client $client, string $name, $value): void
{
    $prop = (new ReflectionClass(Client::class))->getProperty($name);
    $prop->setAccessible(true);
    $prop->setValue($client, $value);
}

/**
 * Run $fn while capturing stdout; return the captured string.
 *
 * @param callable():void $fn
 */
function captureEcho(callable $fn): string
{
    ob_start();
    $fn();
    $out = ob_get_clean();

    return $out === false ? '' : $out;
}

// ---------------------------------------------------------------------------
// the `!$result` error-guard arm (echo $this->error(); return;)
// ---------------------------------------------------------------------------

it('subscribe wrapper echoes the stored error and bails when handed a falsey result', function () {
    $client = subClient();
    $userCalled = false;
    $client->subscribe('chan', function () use (&$userCalled) {
        $userCalled = true;
    });
    subSet($client, '_error', 'boom-subscribe');
    $wrapper = subWrapper($client);

    $out = captureEcho(fn () => $wrapper(false));

    expect($out)->toBe('boom-subscribe');
    expect($userCalled)->toBeFalse();
});

it('pSubscribe wrapper echoes the stored error and bails when handed a falsey result', function () {
    $client = subClient();
    $userCalled = false;
    $client->pSubscribe('p*', function () use (&$userCalled) {
        $userCalled = true;
    });
    subSet($client, '_error', 'boom-psub');
    $wrapper = subWrapper($client);

    $out = captureEcho(fn () => $wrapper(false));

    expect($out)->toBe('boom-psub');
    expect($userCalled)->toBeFalse();
});

it('sSubscribe wrapper echoes the stored error and bails when handed a falsey result', function () {
    $client = subClient();
    $userCalled = false;
    $client->sSubscribe('chan', function () use (&$userCalled) {
        $userCalled = true;
    });
    subSet($client, '_error', 'boom-ssub');
    $wrapper = subWrapper($client);

    $out = captureEcho(fn () => $wrapper(false));

    expect($out)->toBe('boom-ssub');
    expect($userCalled)->toBeFalse();
});

// ---------------------------------------------------------------------------
// the message / pmessage / smessage delivery arms
// ---------------------------------------------------------------------------

it('subscribe wrapper forwards a message frame as (channel, payload, client)', function () {
    $client = subClient();
    $seen = null;
    $client->subscribe('chan', function ($channel, $payload, $c) use (&$seen) {
        $seen = [$channel, $payload, $c];
    });
    $wrapper = subWrapper($client);

    $wrapper(['message', 'chan', 'hello']);

    expect($seen[0])->toBe('chan');
    expect($seen[1])->toBe('hello');
    expect($seen[2])->toBe($client);
});

it('pSubscribe wrapper forwards a pmessage frame as (pattern, channel, payload, client)', function () {
    $client = subClient();
    $seen = null;
    $client->pSubscribe('p*', function ($pattern, $channel, $payload, $c) use (&$seen) {
        $seen = [$pattern, $channel, $payload, $c];
    });
    $wrapper = subWrapper($client);

    $wrapper(['pmessage', 'p*', 'pchan', 'pay']);

    expect($seen[0])->toBe('p*');
    expect($seen[1])->toBe('pchan');
    expect($seen[2])->toBe('pay');
    expect($seen[3])->toBe($client);
});

it('sSubscribe wrapper forwards an smessage frame as (channel, payload, client)', function () {
    $client = subClient();
    $seen = null;
    $client->sSubscribe('chan', function ($channel, $payload, $c) use (&$seen) {
        $seen = [$channel, $payload, $c];
    });
    $wrapper = subWrapper($client);

    $wrapper(['smessage', 'chan', 'shello']);

    expect($seen[0])->toBe('chan');
    expect($seen[1])->toBe('shello');
    expect($seen[2])->toBe($client);
});

// ---------------------------------------------------------------------------
// the subscribe-ack arm (swallowed) and the unsubscribe-ack delegation
// ---------------------------------------------------------------------------

it('subscribe wrapper swallows the subscribe ack without invoking the user cb', function () {
    $client = subClient();
    $userCalled = false;
    $client->subscribe('chan', function () use (&$userCalled) {
        $userCalled = true;
    });
    $wrapper = subWrapper($client);

    $wrapper(['subscribe', 'chan', 1]);

    expect($userCalled)->toBeFalse();
    // The lock-bearing entry is still queued (ack does not tear down on subscribe).
    expect(subProp($client, '_queue'))->toHaveCount(1);
});

it('subscribe wrapper delegates an unsubscribe ack (remaining 0) to handleUnsubscribeAck teardown', function () {
    $client = subClient();
    $client->subscribe('chan', function () {});
    // Simulate the live subscribe lock that the ack must clear.
    subSet($client, '_subscribe', true);
    $wrapper = subWrapper($client);

    $wrapper(['unsubscribe', 'chan', 0]);

    // handleUnsubscribeAck cleared the lock and dropped the pinned SUBSCRIBE head.
    expect(subProp($client, '_subscribe'))->toBeFalse();
    expect(subProp($client, '_queue'))->toBe([]);
});

// ---------------------------------------------------------------------------
// the default: unknown-response-type diagnostic arm
// ---------------------------------------------------------------------------

it('subscribe wrapper writes a diagnostic for an unknown response type', function () {
    $client = subClient();
    $client->subscribe('chan', function () {});
    $wrapper = subWrapper($client);

    $out = captureEcho(fn () => $wrapper(['bogus-type', 'x']));

    expect($out)->toContain('unknow response type for subscribe');
    expect($out)->toContain('buffer:');
});

it('pSubscribe wrapper writes a diagnostic for an unknown response type', function () {
    $client = subClient();
    $client->pSubscribe('p*', function () {});
    $wrapper = subWrapper($client);

    $out = captureEcho(fn () => $wrapper(['bogus-type', 'x']));

    expect($out)->toContain('unknow response type for psubscribe');
});

it('sSubscribe wrapper writes a diagnostic for an unknown response type', function () {
    $client = subClient();
    $client->sSubscribe('chan', function () {});
    $wrapper = subWrapper($client);

    $out = captureEcho(fn () => $wrapper(['bogus-type', 'x']));

    expect($out)->toContain('unknow response type for ssubscribe');
});

// ---------------------------------------------------------------------------
// assertNoActiveStream — the second-stream guard throws (pure, no socket)
// ---------------------------------------------------------------------------

it('a second subscribe-family call throws because a stream is already pending', function () {
    $client = subClient();
    $client->subscribe('chan', function () {});

    // Every subscribe-family entry point routes through assertNoActiveStream();
    // with a SUBSCRIBE already queued, streamActiveOrPending() is true.
    expect(fn () => $client->subscribe('other', function () {}))
        ->toThrow(\Workerman\Redis\Exception::class, 'active or pending');
    expect(fn () => $client->pSubscribe('p*', function () {}))
        ->toThrow(\Workerman\Redis\Exception::class, 'one stream per');
    expect(fn () => $client->sSubscribe('s', function () {}))
        ->toThrow(\Workerman\Redis\Exception::class);
});
