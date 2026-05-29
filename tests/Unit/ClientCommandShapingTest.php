<?php

use Workerman\Redis\Client;

/*
|--------------------------------------------------------------------------
| Client command-shaping (pure logic, no event loop, no server)
|--------------------------------------------------------------------------
|
| These tests drive Client's argument-shaping / queueing decisions WITHOUT
| a socket or the Workerman event loop. The seam:
|
|   - A Client built with newInstanceWithoutConstructor() never runs the
|     constructor, so connect() is never called and $_connection stays null
|     and no Timer is registered.
|   - queueCommand() -> process() is a guaranteed no-op while $_connection
|     is null (process() returns immediately), so every command method just
|     APPENDS [$wireArgs, time, $cb (, $format)] to $_queue. We then read
|     $_queue back via reflection and assert the exact wire array + whether a
|     trailing callable was popped as the callback.
|
| Bound to Tests\TestCase (Pest binds Unit/ automatically) so this passes
| with no server reachable — that is the point of the tier.
*/

/**
 * Build a Client without running its constructor (no connect(), no Timer,
 * $_connection === null so process() is inert).
 */
function shapingClient(): Client
{
    return (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
}

/**
 * Read a protected/private property off a Client instance.
 *
 * @return mixed
 */
function shapingProp(Client $client, string $name)
{
    $prop = (new ReflectionClass(Client::class))->getProperty($name);
    $prop->setAccessible(true);

    return $prop->getValue($client);
}

/**
 * Return the queued command entries as a list of [wireArgs, callbackOrNull].
 * Drops the timestamp and any format closure so assertions stay stable.
 *
 * @return array<int, array{0: array<int, mixed>, 1: callable|null}>
 */
function shapingQueue(Client $client): array
{
    $out = [];
    foreach (shapingProp($client, '_queue') as $entry) {
        $out[] = [$entry[0], $entry[2] ?? null];
    }

    return $out;
}

// ---------------------------------------------------------------------------
// __call() trailing-callable popping
// ---------------------------------------------------------------------------

it('__call uppercases the verb and prepends it to the args', function () {
    $client = shapingClient();
    $client->get('mykey');

    $queue = shapingQueue($client);
    expect($queue)->toHaveCount(1);
    expect($queue[0][0])->toBe(['GET', 'mykey']);
    expect($queue[0][1])->toBeNull();
});

it('__call pops a trailing callable as the callback when count(args) > 1', function () {
    $client = shapingClient();
    $cb = function () {};
    $client->get('mykey', $cb);

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['GET', 'mykey']);
    expect($queue[0][1])->toBe($cb);
});

it('__call does NOT treat a lone callable arg as a callback (the documented footgun)', function () {
    // count($args) === 1 and the method is not in the exception list, so the
    // callable is sent as a literal command ARG, not popped as the callback.
    $client = shapingClient();
    $cb = function () {};
    $client->get($cb);

    $queue = shapingQueue($client);
    expect($queue[0][0])->toHaveCount(2);
    expect($queue[0][0][0])->toBe('GET');
    expect($queue[0][0][1])->toBe($cb);     // the callable rode along as an arg
    expect($queue[0][1])->toBeNull();        // and was NOT taken as the callback
});

it('__call pops a lone callable for the exception-list verbs (randomKey/multi/exec/discard)', function () {
    foreach (['randomKey', 'multi', 'exec', 'discard'] as $verb) {
        $client = shapingClient();
        $cb = function () {};
        $client->{$verb}($cb);

        $queue = shapingQueue($client);
        // Only the uppercased verb makes it to the wire; the callable is popped.
        expect($queue[0][0])->toBe([strtoupper($verb)]);
        expect($queue[0][1])->toBe($cb);
    }
});

it('__call keeps a non-callable last arg in place even with count > 1', function () {
    // lPush routes through __call (no concrete method). With three args and a
    // non-callable tail, nothing is popped as a callback.
    $client = shapingClient();
    $client->lPush('list', 'a', 'b');

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['LPUSH', 'list', 'a', 'b']);
    expect($queue[0][1])->toBeNull();
});

// ---------------------------------------------------------------------------
// dispatcher() prefix styles
// ---------------------------------------------------------------------------

it('dispatcher glues the verb onto a dot-prefixed family (JSON.SET)', function () {
    $client = shapingClient();
    $client->json('set', 'doc', '$', '{"a":1}');

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['JSON.SET', 'doc', '$', '{"a":1}']);
    expect($queue[0][1])->toBeNull();
});

it('dispatcher splits a space-prefixed family into two wire tokens (CONFIG GET)', function () {
    $client = shapingClient();
    $client->config('get', 'maxmemory');

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['CONFIG', 'GET', 'maxmemory']);
    expect($queue[0][1])->toBeNull();
});

it('dispatcher pops a trailing callable (space-prefix) and uppercases the verb', function () {
    $client = shapingClient();
    $cb = function () {};
    $client->cluster('info', $cb);

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['CLUSTER', 'INFO']);
    expect($queue[0][1])->toBe($cb);
});

it('dispatcher pops a trailing callable (dot-prefix)', function () {
    $client = shapingClient();
    $cb = function () {};
    $client->json('get', 'doc', $cb);

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['JSON.GET', 'doc']);
    expect($queue[0][1])->toBe($cb);
});

it('dispatcher uppercases a lower-case verb for the dot family', function () {
    $client = shapingClient();
    $client->bf('add', 'filter', 'item');

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['BF.ADD', 'filter', 'item']);
});

// ---------------------------------------------------------------------------
// rawCommand()
// ---------------------------------------------------------------------------

it('rawCommand queues the args verbatim with no verb prepended', function () {
    // Invoke through reflection, not $client->rawCommand(...): the @method
    // static tag for rawCommand is declared `...$commandAndArgs, $cb = null`
    // (a parameter after a variadic), which PHPStan reads as max 2 args even
    // though the concrete method is fully variadic. Reflection exercises the
    // real method without tripping the tag and without weakening any type.
    // (The malformed tag is a pre-existing src docblock issue — see report.)
    $client = shapingClient();
    $rawCommand = (new ReflectionClass(Client::class))->getMethod('rawCommand');
    $rawCommand->invoke($client, 'CONFIG', 'GET', 'maxmemory');

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['CONFIG', 'GET', 'maxmemory']);
    expect($queue[0][1])->toBeNull();
});

it('rawCommand pops a trailing callable', function () {
    $client = shapingClient();
    $cb = function () {};
    $client->rawCommand('PING', $cb);

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['PING']);
    expect($queue[0][1])->toBe($cb);
});

it('rawCommand throws InvalidArgumentException (SPL, not the package Exception) when empty', function () {
    $client = shapingClient();

    expect(fn () => $client->rawCommand())
        ->toThrow(\InvalidArgumentException::class, 'rawCommand requires at least the command name');
});

it('rawCommand throws InvalidArgumentException when only a callable is passed', function () {
    // The callable is popped first, leaving zero args -> the empty check fires.
    $client = shapingClient();

    expect(fn () => $client->rawCommand(function () {}))
        ->toThrow(\InvalidArgumentException::class);

    // And nothing was queued.
    expect(shapingProp($client, '_queue'))->toBe([]);
});

// ---------------------------------------------------------------------------
// select() / auth() argument shaping
// ---------------------------------------------------------------------------

it('select shapes a SELECT command with the db number', function () {
    $client = shapingClient();
    $client->select(3);

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['SELECT', 3]);
    // select() supplies a default no-op callback when none is given.
    expect($queue[0][1])->toBeCallable();
});

it('auth shapes an AUTH command with a single password', function () {
    $client = shapingClient();
    $client->auth('s3cret');

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['AUTH', 's3cret']);
});

it('auth shapes an AUTH command with a username+password array (ACL auth)', function () {
    $client = shapingClient();
    $client->auth(['alice', 's3cret']);

    $queue = shapingQueue($client);
    expect($queue[0][0])->toBe(['AUTH', 'alice', 's3cret']);
});

// ---------------------------------------------------------------------------
// error() getter
// ---------------------------------------------------------------------------

it('error() returns the empty string by default and the stored error after one is set', function () {
    $client = shapingClient();
    expect($client->error())->toBe('');

    $prop = (new ReflectionClass(Client::class))->getProperty('_error');
    $prop->setAccessible(true);
    $prop->setValue($client, 'Workerman Redis Wait Timeout (600 seconds)');

    expect($client->error())->toBe('Workerman Redis Wait Timeout (600 seconds)');
});
