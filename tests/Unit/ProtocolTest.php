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

it('decodes a nested-array reply (SCAN shape)', function () {
    // *2\r\n$1\r\n0\r\n*2\r\n$3\r\nfoo\r\n$3\r\nbar\r\n
    $wire = "*2\r\n\$1\r\n0\r\n*2\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n";
    [$type, $value] = Redis::decode($wire);
    expect($type)->toBe('*');
    expect($value)->toBe(['0', ['foo', 'bar']]);
});

it('decodes a nested null bulk inside an array (MGET-with-missing-key shape)', function () {
    // *3\r\n$1\r\na\r\n$-1\r\n$1\r\nb\r\n  — middle element is a nil bulk
    // at $offset > 0, which used to be missed by the strpos===0 check.
    $wire = "*3\r\n\$1\r\na\r\n\$-1\r\n\$1\r\nb\r\n";
    [$type, $value] = Redis::decode($wire);
    expect($type)->toBe('*');
    expect($value)->toBe(['a', null, 'b']);
});

it('decodes a nested null array inside an array', function () {
    // *2\r\n$1\r\na\r\n*-1\r\n — null multi-bulk as a nested element.
    $wire = "*2\r\n\$1\r\na\r\n*-1\r\n";
    [$type, $value] = Redis::decode($wire);
    expect($type)->toBe('*');
    expect($value)->toBe(['a', null]);
});

it('decodes an empty array reply (*0)', function () {
    // *0\r\n — valid RESP empty multi-bulk.
    $wire = "*0\r\n";
    [$type, $value] = Redis::decode($wire);
    expect($type)->toBe('*');
    expect($value)->toBe([]);
});

it('encodes an empty-string element as a zero-length bulk string', function () {
    // Empty strings are valid RESP bulk strings: $0\r\n\r\n
    $wire = Redis::encode(['SET', 'key', '']);
    expect($wire)->toBe("*3\r\n\$3\r\nSET\r\n\$3\r\nkey\r\n\$0\r\n\r\n");
});

it('decodes a deeply nested array within MAX_DEPTH without error', function () {
    // Build a 60-deep nested RESP array: *1\r\n ( repeated 59 times ) $3\r\nend\r\n
    // MAX_DEPTH is 64, so this must succeed and preserve the nesting.
    $depth = 60;
    $wire = str_repeat("*1\r\n", $depth) . "\$3\r\nend\r\n";

    [$type, $value] = Redis::decode($wire);

    expect($type)->toBe('*');

    // Unwrap all nesting levels; every level is a single-element array.
    // There are $depth layers of *1 wrapping the leaf string, so we must
    // peel exactly $depth times to reach 'end'.
    $node = $value;
    for ($i = 0; $i < $depth; $i++) {
        expect($node)->toBeArray();
        expect(count($node))->toBe(1);
        $node = $node[0];
    }
    expect($node)->toBe('end');
});

it('decodes an array deeper than MAX_DEPTH as a protocol error', function () {
    // Build a 70-deep nested RESP array — beyond the MAX_DEPTH of 64.
    // decodeOne() must propagate the depth-exceeded error upward.
    $depth = 70;
    $wire = str_repeat("*1\r\n", $depth) . "\$3\r\nend\r\n";

    [$type, $value] = Redis::decode($wire);

    expect($type)->toBe('!');
    expect($value)->toBe('protocol error: max array depth exceeded');
});

it('input returns 0 for a truncated nested-array frame', function () {
    // A valid SCAN reply looks like:
    //   *2\r\n$1\r\n0\r\n*2\r\n$3\r\nfoo\r\n$3\r\nbar\r\n
    // Truncate the last element mid-bulk-string: cut off after "$3\r\nb"
    // (the second element of the inner array is incomplete).
    $truncated = "*2\r\n\$1\r\n0\r\n*2\r\n\$3\r\nfoo\r\n\$3\r\nb";

    // input() requires a ConnectionInterface — extend the abstract class with
    // stub implementations of all abstract methods.
    $connection = new class extends \Workerman\Connection\ConnectionInterface {
        public function send(mixed $sendBuffer, bool $raw = false): bool|null { return null; }
        public function getRemoteIp(): string { return ''; }
        public function getRemotePort(): int { return 0; }
        public function getRemoteAddress(): string { return ''; }
        public function getLocalIp(): string { return ''; }
        public function getLocalPort(): int { return 0; }
        public function getLocalAddress(): string { return ''; }
        public function isIpV4(): bool { return true; }
        public function isIpV6(): bool { return false; }
        public function close(mixed $data = null, bool $raw = false): void {}
    };

    $len = Redis::input($truncated, $connection);

    // 0 means "need more data" — do not advance the read pointer.
    expect($len)->toBe(0);
});
