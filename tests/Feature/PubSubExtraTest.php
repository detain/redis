<?php

/*
|--------------------------------------------------------------------------
| Sharded pub/sub + PUBSUB introspection coverage
|--------------------------------------------------------------------------
|
| Covers SPUBLISH and SSUBSCRIBE (sharded pub/sub) plus the PUBSUB CHANNELS
| subcommand. Sharded pub/sub is a Redis 7.0+ cluster feature; on a non-
| cluster server (including Dragonfly's standalone mode, which is the CI
| target here) the commands are still implemented but behave like single-
| shard variants of PUBLISH/SUBSCRIBE — which is exactly what we need to
| confirm the wiring is correct.
|
| If a future test target lacks SPUBLISH/SSUBSCRIBE entirely, the server
| will reply with an -ERR and the assertion path will fail noisily — at
| that point the test should be gated on a server-capability probe rather
| than silently skipped.
*/

it('sPublish returns the subscriber count when nobody is listening', function () {

    $result = runInWorker(<<<'PHP'
        $redis->sPublish('pest:ps:t1:channel', 'hello', function ($n) use ($emit) {
            $emit($n);
        });
    PHP);

    expect($result)->toBe(0);
});

it('sSubscribe receives a message published via sPublish', function () {

    $result = runInWorker(<<<'PHP'
        $sub = new Workerman\Redis\Client('redis://127.0.0.1:6379');
        $pub = new Workerman\Redis\Client('redis://127.0.0.1:6379');
        $sub->sSubscribe(['pest:ps:t2:chan'], function ($channel, $message, $client) use ($emit) {
            $emit(['channel' => $channel, 'message' => $message]);
        });
        // Give SSUBSCRIBE a moment to register before publishing.
        \Workerman\Timer::add(0.2, function () use ($pub) {
            $pub->sPublish('pest:ps:t2:chan', 'sharded-hello');
        }, [], false);
    PHP, 5);

    expect($result)->toBeArray();
    expect($result['channel'])->toBe('pest:ps:t2:chan');
    expect($result['message'])->toBe('sharded-hello');
});

it('pubSub CHANNELS returns active channels matching a pattern', function () {

    $result = runInWorker(<<<'PHP'
        $sub = new Workerman\Redis\Client('redis://127.0.0.1:6379');
        $sub->subscribe(['pest:ps:t3:chan-a'], function ($ch, $msg) {});
        \Workerman\Timer::add(0.2, function () use ($redis, $emit) {
            $redis->pubSub('CHANNELS', 'pest:ps:t3:*', function ($channels) use ($emit) {
                $emit($channels);
            });
        }, [], false);
    PHP, 5);

    expect($result)->toBeArray();
    expect(count($result))->toBeGreaterThanOrEqual(1);
});
