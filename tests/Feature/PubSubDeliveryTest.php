<?php

/*
|--------------------------------------------------------------------------
| Plain pub/sub delivery + PUBLISH count + PUBSUB introspection
|--------------------------------------------------------------------------
|
| PubSubExtraTest covers the SHARDED family (sSubscribe/sPublish) and
| pubSub('CHANNELS'). This file fills the non-shard gaps:
|   - subscribe()  -> publish() -> message callback (channel + payload)
|   - pSubscribe() -> publish() -> pmessage callback (pattern + channel + payload)
|   - publish() receiver count (0 with no subscriber, >=1 with one)
|   - pubSub('NUMSUB', channel) and pubSub('NUMPAT')
|   - multi-channel subscribe delivery on each subscribed channel
|   - unsubscribe delivery semantics: after unsubscribe a later publish is
|     NOT delivered.
|
| Every streaming test is bounded two ways so it can never hang CI:
|   1. A SECOND client publishes from inside a Workerman\Timer that fires
|      AFTER the subscribe ack has had time to register; the message
|      callback calls $emit() once and stops.
|   2. A non-recurring Workerman\Timer fallback calls $fail('timeout ...')
|      well inside the runInWorker timeout, so a missed message fails fast
|      instead of hanging the harness.
*/

it('subscribe receives a message published by a second client', function () {

    $result = runInWorker(<<<'PHP'
        $pub = new Workerman\Redis\Client(getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379');
        $redis->subscribe(['pest:g6:deliver:1'], function ($channel, $message, $client) use ($emit) {
            $emit(['channel' => $channel, 'message' => $message]);
        });
        // Publish only after the SUBSCRIBE ack has had time to register.
        \Workerman\Timer::add(0.3, function () use ($pub) {
            $pub->publish('pest:g6:deliver:1', 'plain-hello');
        }, [], false);
        // Hard timeout so a missed message fails fast (well under runInWorker's 8s).
        \Workerman\Timer::add(5, function () use ($fail) {
            $fail('timeout: no message delivered to subscribe()');
        }, [], false);
    PHP, 8);

    expect($result)->toBeArray();
    expect($result['channel'])->toBe('pest:g6:deliver:1');
    expect($result['message'])->toBe('plain-hello');
});

it('pSubscribe receives a pmessage matching the pattern', function () {

    $result = runInWorker(<<<'PHP'
        $pub = new Workerman\Redis\Client(getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379');
        $redis->pSubscribe(['pest:g6:news:*'], function ($pattern, $channel, $message, $client) use ($emit) {
            $emit(['pattern' => $pattern, 'channel' => $channel, 'message' => $message]);
        });
        \Workerman\Timer::add(0.3, function () use ($pub) {
            $pub->publish('pest:g6:news:sports', 'goal!');
        }, [], false);
        \Workerman\Timer::add(5, function () use ($fail) {
            $fail('timeout: no pmessage delivered to pSubscribe()');
        }, [], false);
    PHP, 8);

    expect($result)->toBeArray();
    expect($result['pattern'])->toBe('pest:g6:news:*');
    expect($result['channel'])->toBe('pest:g6:news:sports');
    expect($result['message'])->toBe('goal!');
});

it('publish returns 0 when no client is subscribed', function () {

    $result = runInWorker(<<<'PHP'
        $redis->publish('pest:g6:count:none', 'into-the-void', function ($n) use ($emit) {
            $emit($n);
        });
    PHP);

    expect($result)->toBe(0);
});

it('publish returns at least 1 when a client is subscribed', function () {

    $result = runInWorker(<<<'PHP'
        $sub = new Workerman\Redis\Client(getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379');
        $sub->subscribe(['pest:g6:count:one'], function ($channel, $message) {});
        // Publish after the subscriber has registered, then report the count.
        \Workerman\Timer::add(0.3, function () use ($redis, $emit) {
            $redis->publish('pest:g6:count:one', 'hi', function ($n) use ($emit) {
                $emit($n);
            });
        }, [], false);
        \Workerman\Timer::add(5, function () use ($fail) {
            $fail('timeout: publish count callback never fired');
        }, [], false);
    PHP, 8);

    expect($result)->toBeInt();
    expect($result)->toBeGreaterThanOrEqual(1);
});

it('pubSub NUMSUB reports the subscriber count for a channel', function () {

    $result = runInWorker(<<<'PHP'
        $sub = new Workerman\Redis\Client(getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379');
        $sub->subscribe(['pest:g6:numsub:chan'], function ($channel, $message) {});
        \Workerman\Timer::add(0.3, function () use ($redis, $emit) {
            $redis->pubSub('NUMSUB', 'pest:g6:numsub:chan', function ($reply) use ($emit) {
                $emit($reply);
            });
        }, [], false);
        \Workerman\Timer::add(5, function () use ($fail) {
            $fail('timeout: pubSub NUMSUB callback never fired');
        }, [], false);
    PHP, 8);

    // NUMSUB returns a flat [channel, count, ...] array.
    expect($result)->toBeArray();
    expect($result)->toContain('pest:g6:numsub:chan');
    $idx = array_search('pest:g6:numsub:chan', $result, true);
    expect((int) $result[$idx + 1])->toBeGreaterThanOrEqual(1);
});

it('pubSub NUMPAT reports the number of active patterns', function () {

    $result = runInWorker(<<<'PHP'
        $sub = new Workerman\Redis\Client(getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379');
        $sub->pSubscribe(['pest:g6:numpat:*'], function ($pattern, $channel, $message) {});
        \Workerman\Timer::add(0.3, function () use ($redis, $emit) {
            $redis->pubSub('NUMPAT', function ($n) use ($emit) {
                $emit($n);
            });
        }, [], false);
        \Workerman\Timer::add(5, function () use ($fail) {
            $fail('timeout: pubSub NUMPAT callback never fired');
        }, [], false);
    PHP, 8);

    expect($result)->toBeInt();
    expect($result)->toBeGreaterThanOrEqual(1);
});

it('subscribe to multiple channels delivers on the one that is published', function () {

    $result = runInWorker(<<<'PHP'
        $pub = new Workerman\Redis\Client(getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379');
        $redis->subscribe(['pest:g6:multi:a', 'pest:g6:multi:b'], function ($channel, $message, $client) use ($emit) {
            // Report the first message; we publish to channel b specifically.
            $emit(['channel' => $channel, 'message' => $message]);
        });
        \Workerman\Timer::add(0.3, function () use ($pub) {
            $pub->publish('pest:g6:multi:b', 'on-b');
        }, [], false);
        \Workerman\Timer::add(5, function () use ($fail) {
            $fail('timeout: no message delivered to multi-channel subscribe()');
        }, [], false);
    PHP, 8);

    expect($result)->toBeArray();
    expect($result['channel'])->toBe('pest:g6:multi:b');
    expect($result['message'])->toBe('on-b');
});

it('after unsubscribe a later publish is not delivered', function () {

    // Subscribe, then unsubscribe, then publish. The message callback must
    // NEVER fire (the subscription is gone). We prove the negative by waiting
    // out a bounded window after the publish and reporting "not received";
    // if the callback DID fire it emits "received" and the assertion fails.
    $result = runInWorker(<<<'PHP'
        $pub = new Workerman\Redis\Client(getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379');
        $delivered = false;
        $redis->subscribe(['pest:g6:gone:chan'], function ($channel, $message) use (&$delivered) {
            $delivered = true;
        });
        // After the subscribe ack lands, fully unsubscribe; once that ack clears
        // the lock, publish to the (now dead) channel and wait a window.
        \Workerman\Timer::add(0.3, function () use ($redis, $pub, $emit, &$delivered) {
            $redis->unsubscribe('pest:g6:gone:chan', function () use ($redis, $pub, $emit, &$delivered) {
                $pub->publish('pest:g6:gone:chan', 'should-not-arrive');
                // Give any (erroneous) delivery time to land, then report.
                \Workerman\Timer::add(0.8, function () use ($emit, &$delivered) {
                    $emit($delivered ? 'received' : 'not received');
                }, [], false);
            });
        }, [], false);
        \Workerman\Timer::add(6, function () use ($fail) {
            $fail('timeout: unsubscribe-then-publish probe never completed');
        }, [], false);
    PHP, 9);

    expect($result)->toBe('not received');
});
