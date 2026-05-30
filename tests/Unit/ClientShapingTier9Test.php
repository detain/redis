<?php

use Workerman\Redis\Client;

/*
|--------------------------------------------------------------------------
| Group 9 close-out — remaining pure-logic Client branches (no server)
|--------------------------------------------------------------------------
|
| Same in-process seam as ClientCommandShapingTest: a Client built with
| newInstanceWithoutConstructor() never connects, so process() is inert and
| every command method just APPENDS [$wireArgs, time, $cb (, $format)] to
| $_queue. We read $_queue back via reflection and assert the exact wire
| array, exercise the per-method "callable in an options/positional slot"
| shortcuts, the trailing-null pop in the dotted dispatchers, the formatter
| early-return guards, and the small throw/flag side effects (xAdd empty
| message, shutdown's $_quitting). None of these need a socket, an event
| loop, or a reachable server.
|
| These are the genuinely-reachable branches the Feature integration suite
| can't hit cheaply because they are argument-massaging code paths that only
| differ in HOW the wire array is built, not in what the server replies.
*/

function t9Client(): Client
{
    return (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
}

/**
 * Invoke a Client command via a dynamic method name.
 *
 * Several command methods are overloaded through func_get_args(): the slot
 * declared `$cb = null` (or `array $options = []`) actually accepts a TTL /
 * increment / flat-options value in the "second form" (e.g.
 * set($key, $value, $ttl) -> SETEX, incr($key, $num) -> INCRBY,
 * geoRadiusRo(..., $callable) -> callback). PHPStan only sees the narrow
 * declared type and rejects passing an int / callable there. Routing those
 * second-form calls through a variable method name faithfully models the
 * dynamic-arg contract and keeps static analysis clean WITHOUT weakening the
 * assertion (we still hit the real production method).
 *
 * @param  mixed ...$args
 * @return mixed
 */
function t9Call(Client $client, string $method, ...$args)
{
    return $client->$method(...$args);
}

/**
 * @return mixed
 */
function t9Prop(Client $client, string $name)
{
    $prop = (new ReflectionClass(Client::class))->getProperty($name);
    $prop->setAccessible(true);

    return $prop->getValue($client);
}

function t9SetProp(Client $client, string $name, $value): void
{
    $prop = (new ReflectionClass(Client::class))->getProperty($name);
    $prop->setAccessible(true);
    $prop->setValue($client, $value);
}

/**
 * The wire args of the single queued entry.
 *
 * @return array<int, mixed>
 */
function t9WireArgs(Client $client): array
{
    /** @var array<int, mixed> $queue */
    $queue = t9Prop($client, '_queue');
    $entry = $queue[array_key_first($queue)];

    return (array) $entry[0];
}

/**
 * The stored callback (entry[2]) of the single queued entry.
 *
 * @return mixed
 */
function t9Cb(Client $client)
{
    /** @var array<int, mixed> $queue */
    $queue = t9Prop($client, '_queue');
    $entry = $queue[array_key_first($queue)];

    return $entry[2] ?? null;
}

/**
 * The stored format closure (entry[3]) of the single queued entry.
 *
 * @return mixed
 */
function t9Format(Client $client)
{
    /** @var array<int, mixed> $queue */
    $queue = t9Prop($client, '_queue');
    $entry = $queue[array_key_first($queue)];

    return $entry[3] ?? null;
}

// ---------------------------------------------------------------------------
// set() / incr() / decr() second-form (non-callable 3rd/2nd arg) branches
// ---------------------------------------------------------------------------

it('set(key, value, ttl) routes to SETEX with the ttl in the time slot', function () {
    $client = t9Client();
    t9Call($client, 'set', 'k', 'v', 30);

    expect(t9WireArgs($client))->toBe(['SETEX', 'k', 30, 'v']);
    expect(t9Cb($client))->toBeNull();
});

it('set(key, value, ttl, cb) routes to SETEX and keeps the 4th-arg callback', function () {
    $client = t9Client();
    $cb = function () {};
    t9Call($client, 'set', 'k', 'v', 30, $cb);

    expect(t9WireArgs($client))->toBe(['SETEX', 'k', 30, 'v']);
    expect(t9Cb($client))->toBe($cb);
});

it('incr(key, num) routes to INCRBY', function () {
    $client = t9Client();
    t9Call($client, 'incr', 'n', 5);

    expect(t9WireArgs($client))->toBe(['INCRBY', 'n', 5]);
});

it('incr(key, num, cb) routes to INCRBY and keeps the callback', function () {
    $client = t9Client();
    $cb = function () {};
    t9Call($client, 'incr', 'n', 5, $cb);

    expect(t9WireArgs($client))->toBe(['INCRBY', 'n', 5]);
    expect(t9Cb($client))->toBe($cb);
});

it('decr(key, num) routes to DECRBY', function () {
    $client = t9Client();
    t9Call($client, 'decr', 'n', 3);

    expect(t9WireArgs($client))->toBe(['DECRBY', 'n', 3]);
});

it('decr(key, num, cb) routes to DECRBY and keeps the callback', function () {
    $client = t9Client();
    $cb = function () {};
    t9Call($client, 'decr', 'n', 3, $cb);

    expect(t9WireArgs($client))->toBe(['DECRBY', 'n', 3]);
    expect(t9Cb($client))->toBe($cb);
});

// ---------------------------------------------------------------------------
// sort() / sortRo() option flattening
// ---------------------------------------------------------------------------

it('sort() pulls a leading sort token then flattens scalar and list options', function () {
    $client = t9Client();
    $client->sort('mylist', [
        'sort' => 'BY weight_*',
        'LIMIT' => [0, 5],
        'ALPHA' => '',
    ]);

    // 'sort' is emitted first as a bare token, then each remaining option name
    // followed by its scalar value or each element of its list value.
    expect(t9WireArgs($client))->toBe(['SORT', 'mylist', 'BY weight_*', 'LIMIT', 0, 5, 'ALPHA', '']);
});

it('sortRo() treats a callable in the options slot as the callback', function () {
    $client = t9Client();
    $cb = function () {};
    $client->sortRo('mylist', $cb);

    expect(t9WireArgs($client))->toBe(['SORT_RO', 'mylist']);
    expect(t9Cb($client))->toBe($cb);
});

it('sortRo() flattens both scalar and list options', function () {
    $client = t9Client();
    $client->sortRo('mylist', ['LIMIT' => [0, 3], 'ALPHA' => '']);

    expect(t9WireArgs($client))->toBe(['SORT_RO', 'mylist', 'LIMIT', 0, 3, 'ALPHA', '']);
});

// ---------------------------------------------------------------------------
// xAdd() — empty-message guard + MAXLEN ~ shaping
// ---------------------------------------------------------------------------

it('xAdd() throws InvalidArgumentException on an empty message', function () {
    $client = t9Client();

    expect(fn () => $client->xAdd('s', '*', []))
        ->toThrow(\InvalidArgumentException::class, 'non-empty field => value message');
    expect(t9Prop($client, '_queue'))->toBe([]);
});

it('xAdd() emits MAXLEN ~ n when approximate is true and flattens the message', function () {
    $client = t9Client();
    $client->xAdd('s', '*', ['f' => 'v'], 100, true);

    expect(t9WireArgs($client))->toBe(['XADD', 's', 'MAXLEN', '~', 100, '*', 'f', 'v']);
});

it('xAdd() folds a trailing callable in the maxLen slot as the callback', function () {
    $client = t9Client();
    $cb = function () {};
    $client->xAdd('s', '*', ['f' => 'v'], $cb);

    expect(t9WireArgs($client))->toBe(['XADD', 's', '*', 'f', 'v']);
    expect(t9Cb($client))->toBe($cb);
});

it('xAdd() folds a trailing callable in the approximate slot as the callback', function () {
    $client = t9Client();
    $cb = function () {};
    $client->xAdd('s', '*', ['f' => 'v'], 50, $cb);

    expect(t9WireArgs($client))->toBe(['XADD', 's', 'MAXLEN', 50, '*', 'f', 'v']);
    expect(t9Cb($client))->toBe($cb);
});

// ---------------------------------------------------------------------------
// hMGet() / hGetAll() formatter early-return guards (non-array passthrough)
// ---------------------------------------------------------------------------

it('hMGet() formatter returns a non-array reply unchanged and combines an array reply', function () {
    $client = t9Client();
    $client->hMGet('h', ['a', 'b']);
    $format = t9Format($client);
    expect($format)->toBeCallable();

    // Non-array (error string / false) passes straight through.
    expect($format(false))->toBeFalse();
    expect($format('-ERR boom'))->toBe('-ERR boom');
    // Array reply is combined against the requested fields.
    expect($format(['1', '2']))->toBe(['a' => '1', 'b' => '2']);
});

it('hGetAll() formatter returns a non-array reply unchanged and folds pairs into a map', function () {
    $client = t9Client();
    $client->hGetAll('h');
    $format = t9Format($client);
    expect($format)->toBeCallable();

    expect($format(false))->toBeFalse();
    expect($format(['f1', 'v1', 'f2', 'v2']))->toBe(['f1' => 'v1', 'f2' => 'v2']);
});

// ---------------------------------------------------------------------------
// geo / eval read-only variants — callable-in-options-slot shortcuts
// ---------------------------------------------------------------------------

it('geoRadiusRo() takes a callable options arg as the callback', function () {
    $client = t9Client();
    $cb = function () {};
    t9Call($client, 'geoRadiusRo', 'k', 1.0, 2.0, 100, 'm', $cb);

    expect(t9WireArgs($client))->toBe(['GEORADIUS_RO', 'k', 1.0, 2.0, 100, 'm']);
    expect(t9Cb($client))->toBe($cb);
});

it('geoRadiusByMemberRo() takes a callable options arg as the callback', function () {
    $client = t9Client();
    $cb = function () {};
    t9Call($client, 'geoRadiusByMemberRo', 'k', 'member', 100, 'm', $cb);

    expect(t9WireArgs($client))->toBe(['GEORADIUSBYMEMBER_RO', 'k', 'member', 100, 'm']);
    expect(t9Cb($client))->toBe($cb);
});

it('evalRo() folds a callable args slot and a callable numKeys slot as the callback', function () {
    $clientA = t9Client();
    $cbA = function () {};
    $clientA->evalRo('return 1', $cbA);
    expect(t9WireArgs($clientA))->toBe(['EVAL_RO', 'return 1', 0]);
    expect(t9Cb($clientA))->toBe($cbA);

    $clientB = t9Client();
    $cbB = function () {};
    // args given, numKeys is the callback -> numKeys defaults to count($args).
    $clientB->evalRo('return KEYS[1]', ['k1', 'k2'], $cbB);
    expect(t9WireArgs($clientB))->toBe(['EVAL_RO', 'return KEYS[1]', 2, 'k1', 'k2']);
    expect(t9Cb($clientB))->toBe($cbB);
});

it('evalShaRo() folds a callable args slot and a callable numKeys slot as the callback', function () {
    $clientA = t9Client();
    $cbA = function () {};
    $clientA->evalShaRo('abc123', $cbA);
    expect(t9WireArgs($clientA))->toBe(['EVALSHA_RO', 'abc123', 0]);
    expect(t9Cb($clientA))->toBe($cbA);

    $clientB = t9Client();
    $cbB = function () {};
    $clientB->evalShaRo('abc123', ['k1'], $cbB);
    expect(t9WireArgs($clientB))->toBe(['EVALSHA_RO', 'abc123', 1, 'k1']);
    expect(t9Cb($clientB))->toBe($cbB);
});

// ---------------------------------------------------------------------------
// hello() with an extra map argument
// ---------------------------------------------------------------------------

it('hello() appends an array extra (AUTH map) after the protover', function () {
    $client = t9Client();
    $client->hello(3, ['AUTH', 'user', 'pass']);

    expect(t9WireArgs($client))->toBe(['HELLO', 3, ['AUTH', 'user', 'pass']]);
});

// ---------------------------------------------------------------------------
// dotted dispatchers — trailing-null pop (the typed-shortcut forwarding path)
// ---------------------------------------------------------------------------

it('json()/bf()/cms()/topk()/ft() drop a trailing null before dispatching', function () {
    $cases = [
        ['json', 'JSON.', ['GET', 'doc', null], ['JSON.GET', 'doc']],
        ['bf', 'BF.', ['EXISTS', 'filter', 'item', null], ['BF.EXISTS', 'filter', 'item']],
        ['cms', 'CMS.', ['QUERY', 'sketch', 'item', null], ['CMS.QUERY', 'sketch', 'item']],
        ['topk', 'TOPK.', ['QUERY', 'tk', 'item', null], ['TOPK.QUERY', 'tk', 'item']],
        ['ft', 'FT.', ['INFO', 'idx', null], ['FT.INFO', 'idx']],
    ];
    foreach ($cases as [$method, $_prefix, $args, $expected]) {
        $client = t9Client();
        $client->{$method}(...$args);
        expect(t9WireArgs($client))->toBe($expected);
        // The trailing null was popped, so there is no stored callback.
        expect(t9Cb($client))->toBeNull();
    }
});

// ---------------------------------------------------------------------------
// json* typed shortcuts — callable-in-path-slot
// ---------------------------------------------------------------------------

it('json* typed getters take a callable path arg as the callback and default path to $', function () {
    $methods = ['jsonType', 'jsonObjKeys', 'jsonObjLen', 'jsonArrLen', 'jsonStrLen', 'jsonDel', 'jsonForget'];
    $expectedVerb = [
        'jsonType' => 'JSON.TYPE',
        'jsonObjKeys' => 'JSON.OBJKEYS',
        'jsonObjLen' => 'JSON.OBJLEN',
        'jsonArrLen' => 'JSON.ARRLEN',
        'jsonStrLen' => 'JSON.STRLEN',
        'jsonDel' => 'JSON.DEL',
        'jsonForget' => 'JSON.FORGET',
    ];
    foreach ($methods as $method) {
        $client = t9Client();
        $cb = function () {};
        $client->{$method}('doc', $cb);
        expect(t9WireArgs($client))->toBe([$expectedVerb[$method], 'doc', '$']);
        expect(t9Cb($client))->toBe($cb);
    }
});

it('jsonMGet() takes a callable path arg as the callback and flattens keys', function () {
    $client = t9Client();
    $cb = function () {};
    $client->jsonMGet(['d1', 'd2'], $cb);

    expect(t9WireArgs($client))->toBe(['JSON.MGET', 'd1', 'd2', '$']);
    expect(t9Cb($client))->toBe($cb);
});

// ---------------------------------------------------------------------------
// cmsMerge() — callable-weights shortcut + WEIGHTS branch
// ---------------------------------------------------------------------------

it('cmsMerge() without weights builds MERGE dest numKeys src...', function () {
    $client = t9Client();
    $client->cmsMerge('dest', 2, ['a', 'b']);

    expect(t9WireArgs($client))->toBe(['CMS.MERGE', 'dest', 2, 'a', 'b']);
});

it('cmsMerge() appends a WEIGHTS clause when weights are given', function () {
    $client = t9Client();
    $client->cmsMerge('dest', 2, ['a', 'b'], [3, 4]);

    expect(t9WireArgs($client))->toBe(['CMS.MERGE', 'dest', 2, 'a', 'b', 'WEIGHTS', 3, 4]);
});

// ---------------------------------------------------------------------------
// ftDropIndex() — DD branch + callable-deleteDocs shortcut
// ---------------------------------------------------------------------------

it('ftDropIndex() without DD omits the DD token', function () {
    $client = t9Client();
    $client->ftDropIndex('idx');

    expect(t9WireArgs($client))->toBe(['FT.DROPINDEX', 'idx']);
});

it('ftDropIndex() with deleteDocs appends DD', function () {
    $client = t9Client();
    $client->ftDropIndex('idx', true);

    expect(t9WireArgs($client))->toBe(['FT.DROPINDEX', 'idx', 'DD']);
});

it('ftDropIndex() folds a callable deleteDocs arg as the callback (no DD)', function () {
    $client = t9Client();
    $cb = function () {};
    $client->ftDropIndex('idx', $cb);

    expect(t9WireArgs($client))->toBe(['FT.DROPINDEX', 'idx']);
    expect(t9Cb($client))->toBe($cb);
});

// ---------------------------------------------------------------------------
// shutdown() — mode shaping, callable-mode shortcut, and the _quitting flag
// ---------------------------------------------------------------------------

it('shutdown() defaults to SAVE and sets the _quitting flag', function () {
    $client = t9Client();
    t9SetProp($client, '_quitting', false);
    $client->shutdown();

    expect(t9WireArgs($client))->toBe(['SHUTDOWN', 'SAVE']);
    expect(t9Prop($client, '_quitting'))->toBeTrue();
});

it('shutdown() with NOSAVE shapes the mode token', function () {
    $client = t9Client();
    $client->shutdown('NOSAVE');

    expect(t9WireArgs($client))->toBe(['SHUTDOWN', 'NOSAVE']);
});

it('shutdown() folds a callable mode arg as the callback and keeps SAVE', function () {
    $client = t9Client();
    $cb = function () {};
    $client->shutdown($cb);

    expect(t9WireArgs($client))->toBe(['SHUTDOWN', 'SAVE']);
    expect(t9Cb($client))->toBe($cb);
});

// ---------------------------------------------------------------------------
// monitor() — pending-stream early return + rejection handler
// ---------------------------------------------------------------------------

it('monitor() is a no-op when a stream is already pending in the queue', function () {
    $client = t9Client();
    // A SUBSCRIBE already sitting in the queue makes streamActiveOrPending()
    // true even though the _subscribe flag is still false (never sent).
    t9SetProp($client, '_queue', [[['SUBSCRIBE', 'chan'], time(), function () {}]]);

    $client->monitor(function () {});

    // No MONITOR entry was appended — the queue is unchanged (length 1).
    expect(t9Prop($client, '_queue'))->toHaveCount(1);
    expect(t9WireArgs($client))->toBe(['SUBSCRIBE', 'chan']);
});

it('monitor() rejection handler clears the lock, drops the pinned entry, and reports false', function () {
    $client = t9Client();
    // Queue a MONITOR entry as process() would have, and set the lock.
    $client->monitor(function ($result) use (&$reported) {
        $reported = $result;
    });
    // Grab the wrapper callback the client stored, then simulate the lock
    // process() would set and feed it a rejection (false).
    $reported = 'untouched';
    $wrapper = t9Cb($client);
    t9SetProp($client, '_monitoring', true);
    // The queued head is the MONITOR entry; the handler should unset it.
    $wrapper(false);

    expect($reported)->toBeFalse();
    expect(t9Prop($client, '_monitoring'))->toBeFalse();
    expect(t9Prop($client, '_queue'))->toBe([]);
});

it('monitor() wrapper swallows the +OK handshake (true) without invoking the user cb', function () {
    $client = t9Client();
    $seen = 'untouched';
    $client->monitor(function ($line) use (&$seen) {
        $seen = $line;
    });
    $wrapper = t9Cb($client);

    // true is the MONITOR +OK handshake — swallowed.
    $wrapper(true);
    expect($seen)->toBe('untouched');

    // A real monitor line is forwarded.
    $wrapper('1700000000.0 [0 127.0.0.1:1] "PING"');
    expect($seen)->toBe('1700000000.0 [0 127.0.0.1:1] "PING"');
});
