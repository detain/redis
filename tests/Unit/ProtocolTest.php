<?php

use Workerman\Redis\Protocols\Redis;

it('encodes a simple flat command into RESP', function () {
    $wire = Redis::encode(['PING']);
    expect($wire)->toBe("*1\r\n\$4\r\nPING\r\n");
});

it('encodes commands with multiple bulk-string args', function () {
    $wire = Redis::encode(['SET', 'foo', 'bar']);
    expect($wire)->toBe("*3\r\n\$3\r\nSET\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n");
});

it('flattens nested array args (MGET-style)', function () {
    $wire = Redis::encode(['MGET', ['a', 'b', 'c']]);
    expect($wire)->toBe("*4\r\n\$4\r\nMGET\r\n\$1\r\na\r\n\$1\r\nb\r\n\$1\r\nc\r\n");
});

it('decodes a simple-string reply', function () {
    [$type, $value] = Redis::decode("+OK\r\n");
    expect($type)->toBe('+');
    expect($value)->toBe('OK');
});

it('decodes an integer reply', function () {
    [$type, $value] = Redis::decode(":42\r\n");
    expect($type)->toBe(':');
    expect($value)->toBe(42);
});

it('decodes a bulk-string reply', function () {
    [$type, $value] = Redis::decode("\$5\r\nhello\r\n");
    expect($type)->toBe('$');
    expect($value)->toBe('hello');
});

it('decodes a null bulk-string reply', function () {
    [$type, $value] = Redis::decode("\$-1\r\n");
    expect($type)->toBe('$');
    expect($value)->toBeNull();
});

it('decodes an array reply with mixed types', function () {
    [$type, $value] = Redis::decode("*3\r\n:1\r\n\$3\r\nfoo\r\n+OK\r\n");
    expect($type)->toBe('*');
    expect($value)->toBe([1, 'foo', 'OK']);
});

it('decodes an error reply', function () {
    [$type, $value] = Redis::decode("-ERR wrong\r\n");
    expect($type)->toBe('-');
    expect($value)->toBe('ERR wrong');
});
