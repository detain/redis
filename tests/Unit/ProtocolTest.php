<?php

use Workerman\Redis\Protocols\Redis;

/**
 * A minimal ConnectionInterface stub so Redis::input() (which only needs the
 * type hint, never actually touches the connection) can be exercised in-process.
 * Returned as a fresh instance per call to avoid cross-test state.
 */
function protocolStubConnection(): \Workerman\Connection\ConnectionInterface
{
    return new class extends \Workerman\Connection\ConnectionInterface {
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
}

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

    $len = Redis::input($truncated, protocolStubConnection());

    // 0 means "need more data" — do not advance the read pointer.
    expect($len)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| input() / measure() frame-length probe
|--------------------------------------------------------------------------
|
| input() returns the byte length of the next complete RESP frame in the
| buffer, or 0 when more bytes are needed. These exercise every measure()
| branch — the cheap, server-free wins that close the last ~10% of the
| protocol class.
*/

it('input sizes a simple-string frame exactly', function () {
    // '+OK\r\n' is 5 bytes; input() must report the full frame length.
    expect(Redis::input("+OK\r\n", protocolStubConnection()))->toBe(5);
});

it('input sizes an integer frame exactly', function () {
    expect(Redis::input(":42\r\n", protocolStubConnection()))->toBe(5);
});

it('input sizes an error frame exactly', function () {
    // '-ERR x\r\n' is 8 bytes.
    expect(Redis::input("-ERR x\r\n", protocolStubConnection()))->toBe(8);
});

it('input sizes a bulk-string frame including the payload and trailing CRLF', function () {
    // '$5\r\nhello\r\n' is 11 bytes.
    expect(Redis::input("\$5\r\nhello\r\n", protocolStubConnection()))->toBe(11);
});

it('input sizes a null bulk-string frame as 5 bytes', function () {
    // '$-1\r\n' — the measure() $-1 fast path returns the literal 5.
    expect(Redis::input("\$-1\r\n", protocolStubConnection()))->toBe(5);
});

it('input sizes a null multi-bulk frame as 5 bytes', function () {
    // '*-1\r\n' — the measure() *-1 fast path returns the literal 5.
    expect(Redis::input("*-1\r\n", protocolStubConnection()))->toBe(5);
});

it('input sizes an empty array frame as 4 bytes', function () {
    expect(Redis::input("*0\r\n", protocolStubConnection()))->toBe(4);
});

it('input sizes a nested multi-bulk (SCAN) frame exactly', function () {
    // *2\r\n$1\r\n0\r\n*2\r\n$3\r\nfoo\r\n$3\r\nbar\r\n — sum of all child frames.
    $wire = "*2\r\n\$1\r\n0\r\n*2\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n";
    expect(Redis::input($wire, protocolStubConnection()))->toBe(\strlen($wire));
});

it('input returns 0 for an empty buffer', function () {
    // measure() hits the `!isset($buffer[$offset])` guard.
    expect(Redis::input('', protocolStubConnection()))->toBe(0);
});

it('input returns 0 when the first line has no CRLF yet', function () {
    // measure() finds no "\r\n" and returns 0 (need more bytes).
    expect(Redis::input("\$5\r\nhel", protocolStubConnection()))->toBe(0);
});

it('input returns 0 for a bulk frame whose payload is not fully buffered', function () {
    // Header says 5 bytes but only 3 of "hello" are present.
    expect(Redis::input("\$5\r\nhel", protocolStubConnection()))->toBe(0);
});

it('input returns 0 when a multi-bulk element header has no CRLF', function () {
    // Outer header complete, but the single child '$3' has no CRLF — the
    // recursive measure() call returns 0, so the whole frame is incomplete.
    expect(Redis::input("*1\r\n\$3", protocolStubConnection()))->toBe(0);
});

it('input bails out with the buffer-length sentinel past MAX_DEPTH', function () {
    // measure() recurses one level per nested '*1'. Beyond MAX_DEPTH (64) it
    // stops descending and returns strlen-offset so input() treats the frame
    // as consumed and the decoder produces the depth-exceeded protocol error.
    // 70 levels > 64, so the sentinel branch (Redis.php line 62) fires.
    $wire = str_repeat("*1\r\n", 70) . "\$3\r\nend\r\n";
    $len = Redis::input($wire, protocolStubConnection());
    // The depth-65 sentinel (strlen-offset) propagates up through the
    // $cursor accumulation to sum to the full buffer length, so the frame is
    // treated as complete and consumed in one shot.
    expect($len)->toBe(\strlen($wire));
});

it('input treats an unknown leading type byte as a consumed (error) frame', function () {
    // measure() default branch returns strlen-offset so input() reports the
    // whole buffer as one frame; decode() then surfaces the protocol error.
    $buffer = "?bogus\r\n";
    expect(Redis::input($buffer, protocolStubConnection()))->toBe(\strlen($buffer));
});

/*
|--------------------------------------------------------------------------
| decode() / decodeOne() error & incomplete branches
|--------------------------------------------------------------------------
*/

it('decodes a binary-safe bulk string containing an embedded CRLF', function () {
    // The $length header makes the payload binary-safe — an embedded \r\n
    // must NOT terminate the value early.
    $payload = "ab\r\ncd";                       // 6 bytes incl. the CRLF
    $wire = '$' . \strlen($payload) . "\r\n" . $payload . "\r\n";
    [$type, $value] = Redis::decode($wire);
    expect($type)->toBe('$');
    expect($value)->toBe($payload);
});

it('decodes a bulk string containing a null byte', function () {
    $payload = "a\0b";
    $wire = '$' . \strlen($payload) . "\r\n" . $payload . "\r\n";
    [$type, $value] = Redis::decode($wire);
    expect($type)->toBe('$');
    expect($value)->toBe($payload);
});

it('decodes a large bulk string (multi-KB) intact', function () {
    $payload = str_repeat('x', 4096);
    $wire = '$' . \strlen($payload) . "\r\n" . $payload . "\r\n";
    [$type, $value] = Redis::decode($wire);
    expect($type)->toBe('$');
    expect($value)->toBe($payload);
});

it('decodes a negative integer reply', function () {
    [$type, $value] = Redis::decode(":-7\r\n");
    expect($type)->toBe(':');
    expect($value)->toBe(-7);
});

it('returns a protocol-error tuple for an unknown leading type byte', function () {
    // decodeOne()'s default branch returns null → decode() builds the '!' tuple
    // with the offending byte and a hex dump of the buffer.
    [$type, $value] = Redis::decode("?nope\r\n");
    expect($type)->toBe('!');
    expect($value)->toContain("got '?'");
    expect($value)->toContain(bin2hex("?nope\r\n"));
});

it('returns a protocol-error tuple for an empty buffer', function () {
    // decodeOne() hits `!isset($buffer[$offset])` → null → decode() reports
    // an empty offending byte.
    [$type, $value] = Redis::decode('');
    expect($type)->toBe('!');
    expect($value)->toContain("got ''");
});

it('returns null-shaped protocol error when the frame has no CRLF', function () {
    // decodeOne() finds no "\r\n" → null → decode() wraps it as a '!' tuple.
    [$type, $value] = Redis::decode("+partial");
    expect($type)->toBe('!');
});

it('propagates a depth-exceeded error from a nested element', function () {
    // An array whose single child blows MAX_DEPTH: the inner decodeOne returns
    // the '!' depth error, which the parent loop propagates instead of nesting.
    $depth = 70;
    $wire = str_repeat("*1\r\n", $depth) . "\$3\r\nend\r\n";
    [$type, $value] = Redis::decode($wire);
    expect($type)->toBe('!');
    expect($value)->toBe('protocol error: max array depth exceeded');
});

it('returns a protocol error when a nested array element has no CRLF', function () {
    // Outer *2 promises two children; the first decodes, but the second
    // ('+ab' with no trailing CRLF) makes decodeOne return null, which the
    // parent loop propagates → decode() surfaces a '!' protocol-error tuple.
    // (A truncated *bulk* would NOT hit this: decodeOne's $ branch substr()s
    // optimistically, trusting input() to have gated completeness first.)
    [$type] = Redis::decode("*2\r\n:1\r\n+ab");
    expect($type)->toBe('!');
});
