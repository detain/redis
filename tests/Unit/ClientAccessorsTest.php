<?php

use Workerman\Redis\Client;

/*
|--------------------------------------------------------------------------
| Client local accessors (phpredis-compat getters, no server)
|--------------------------------------------------------------------------
|
| These cover the real local accessor methods that replaced the broken
| @method stubs (getHost/getPort/getDbNum/getAuth/getTimeout/getReadTimeout/
| isConnected/getLastError/clearLastError/getPersistentID) plus the
| getMultiple() MGET alias. They derive their return value from stored client
| state only — no socket, no event loop, no server round-trip — so the
| in-process newInstanceWithoutConstructor() seam (see ClientCommandShapingTest)
| is sufficient: we set the backing properties via reflection and assert the
| getter output. getMultiple() is the one server command here; with
| $_connection === null process() is inert, so we assert the queued wire array.
*/

/**
 * Build a Client without running its constructor ($_connection stays null,
 * no Timer, no connect()).
 */
function accClient(): Client
{
    return (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
}

/**
 * Set a protected/private property on a Client via reflection.
 *
 * @param mixed $value
 */
function accSet(Client $client, string $name, $value): void
{
    $prop = (new ReflectionClass(Client::class))->getProperty($name);
    $prop->setAccessible(true);
    $prop->setValue($client, $value);
}

/**
 * Read a protected/private property off a Client via reflection.
 *
 * @return mixed
 */
function accGet(Client $client, string $name)
{
    $prop = (new ReflectionClass(Client::class))->getProperty($name);
    $prop->setAccessible(true);

    return $prop->getValue($client);
}

// ---------------------------------------------------------------------------
// getHost / getPort  (parsed from $_address)
// ---------------------------------------------------------------------------

it('getHost parses the host out of the stored address URL', function () {
    $client = accClient();
    accSet($client, '_address', 'redis://127.0.0.1:6379');

    expect($client->getHost())->toBe('127.0.0.1');
});

it('getHost returns null when the address cannot be parsed for a host', function () {
    $client = accClient();
    accSet($client, '_address', '');

    expect($client->getHost())->toBeNull();
});

it('getPort parses the port out of the stored address URL', function () {
    $client = accClient();
    accSet($client, '_address', 'redis://127.0.0.1:6390');

    expect($client->getPort())->toBe(6390);
});

it('getPort defaults to 6379 when the address carries no explicit port', function () {
    $client = accClient();
    accSet($client, '_address', 'redis://127.0.0.1');

    expect($client->getPort())->toBe(6379);
});

// ---------------------------------------------------------------------------
// getDbNum / getAuth  ($_db, $_auth)
// ---------------------------------------------------------------------------

it('getDbNum returns the locally tracked database index as an int', function () {
    $client = accClient();
    accSet($client, '_db', 4);

    expect($client->getDbNum())->toBe(4)->toBeInt();
});

it('getAuth returns the stored string credential', function () {
    $client = accClient();
    accSet($client, '_auth', 's3cret');

    expect($client->getAuth())->toBe('s3cret');
});

it('getAuth returns the stored ACL [user, pass] array credential', function () {
    $client = accClient();
    accSet($client, '_auth', ['alice', 's3cret']);

    expect($client->getAuth())->toBe(['alice', 's3cret']);
});

it('getAuth returns null when no credential was set', function () {
    $client = accClient();
    accSet($client, '_auth', null);

    expect($client->getAuth())->toBeNull();
});

// ---------------------------------------------------------------------------
// getTimeout / getReadTimeout  (from $_options)
// ---------------------------------------------------------------------------

it('getTimeout returns the configured connect_timeout option', function () {
    $client = accClient();
    accSet($client, '_options', ['connect_timeout' => 7]);

    expect($client->getTimeout())->toBe(7);
});

it('getTimeout returns null when connect_timeout is not configured', function () {
    $client = accClient();
    accSet($client, '_options', []);

    expect($client->getTimeout())->toBeNull();
});

it('getReadTimeout returns the configured wait_timeout option', function () {
    $client = accClient();
    accSet($client, '_options', ['wait_timeout' => 120]);

    expect($client->getReadTimeout())->toBe(120);
});

it('getReadTimeout returns null when wait_timeout is not configured', function () {
    $client = accClient();
    accSet($client, '_options', []);

    expect($client->getReadTimeout())->toBeNull();
});

// ---------------------------------------------------------------------------
// isConnected  ($_connection null vs an object)
// ---------------------------------------------------------------------------

it('isConnected returns false when there is no connection', function () {
    $client = accClient();
    accSet($client, '_connection', null);

    expect($client->isConnected())->toBeFalse();
});

it('isConnected returns true only when the connection reports ESTABLISHED', function () {
    $client = accClient();

    // A tiny stand-in exposing getStatus(false); no real socket needed.
    $established = new class {
        public function getStatus($raw = true)
        {
            return 'ESTABLISHED';
        }
    };
    accSet($client, '_connection', $established);
    expect($client->isConnected())->toBeTrue();

    $connecting = new class {
        public function getStatus($raw = true)
        {
            return 'CONNECTING';
        }
    };
    accSet($client, '_connection', $connecting);
    expect($client->isConnected())->toBeFalse();
});

// ---------------------------------------------------------------------------
// getLastError / clearLastError  ($_error sentinel normalisation)
// ---------------------------------------------------------------------------

it('getLastError returns null when no error is stored (the empty-string sentinel)', function () {
    $client = accClient();
    accSet($client, '_error', '');

    expect($client->getLastError())->toBeNull();
});

it('getLastError returns the stored error string when one is set', function () {
    $client = accClient();
    accSet($client, '_error', 'ERR something went wrong');

    expect($client->getLastError())->toBe('ERR something went wrong');
});

it('clearLastError wipes the stored error and returns true', function () {
    $client = accClient();
    accSet($client, '_error', 'ERR boom');

    expect($client->clearLastError())->toBeTrue();
    expect(accGet($client, '_error'))->toBe('');
    expect($client->getLastError())->toBeNull();
});

// ---------------------------------------------------------------------------
// getPersistentID  (always null for this async client)
// ---------------------------------------------------------------------------

it('getPersistentID is always null (no persistent connections)', function () {
    $client = accClient();

    expect($client->getPersistentID())->toBeNull();
});

// ---------------------------------------------------------------------------
// getMultiple  (MGET alias — queues ['MGET', ...$keys])
// ---------------------------------------------------------------------------

it('getMultiple queues an MGET command with the keys in order', function () {
    $client = accClient();
    $client->getMultiple(['k1', 'k2', 'k3']);

    $queue = accGet($client, '_queue');
    expect($queue)->toHaveCount(1);
    expect($queue[0][0])->toBe(['MGET', 'k1', 'k2', 'k3']);
    // No trailing callable supplied -> callback slot is null.
    expect($queue[0][2] ?? null)->toBeNull();
});

it('getMultiple pops a trailing callable as the callback', function () {
    $client = accClient();
    $cb = function () {};
    $client->getMultiple(['k1', 'k2'], $cb);

    $queue = accGet($client, '_queue');
    expect($queue[0][0])->toBe(['MGET', 'k1', 'k2']);
    expect($queue[0][2] ?? null)->toBe($cb);
});
