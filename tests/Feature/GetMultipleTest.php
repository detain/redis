<?php

/*
|--------------------------------------------------------------------------
| getMultiple() — phpredis MGET alias (Group: accessor bug fix)
|--------------------------------------------------------------------------
|
| getMultiple() used to be an unimplemented @method stub: __call() sent the
| literal verb GETMULTIPLE, which both Dragonfly and Redis reject with
| "ERR unknown command". It is now a real method delegating to MGET. This
| proves it round-trips against a live server on BOTH backends: values come
| back in key order, with null for any missing key.
|
| Keys use a pest:gm: prefix.
*/

it('getMultiple returns the values in order with null for a missing key', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:gm:a', 'pest:gm:b', 'pest:gm:missing', function () use ($redis, $emit) {
            $redis->set('pest:gm:a', 'one', function () use ($redis, $emit) {
                $redis->set('pest:gm:b', 'two', function () use ($redis, $emit) {
                    $redis->getMultiple(['pest:gm:a', 'pest:gm:missing', 'pest:gm:b'], function ($values) use ($emit) {
                        $emit($values);
                    });
                });
            });
        });
    PHP);

    expect($result)->toBe(['one', null, 'two']);
});
