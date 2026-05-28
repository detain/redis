<?php

it('round-trips SET and GET via runInWorker', function () {
    /** @var \Tests\RedisTestCase $this */
    $result = $this->runInWorker(<<<'PHP'
        $redis->set('pest:smoke:k', 'hello-pest');
        $redis->get('pest:smoke:k', function ($value) use ($emit) {
            $emit($value);
        });
    PHP);
    expect($result)->toBe('hello-pest');
});

it('routes __call commands through the dispatcher', function () {
    /** @var \Tests\RedisTestCase $this */
    $result = $this->runInWorker(<<<'PHP'
        $redis->del('pest:smoke:counter');
        $redis->incr('pest:smoke:counter');
        $redis->incr('pest:smoke:counter');
        $redis->incr('pest:smoke:counter', function ($value) use ($emit) {
            $emit($value);
        });
    PHP);
    expect($result)->toBe(3);
});
