<?php

/*
|--------------------------------------------------------------------------
| Connection / lifecycle commands (Group 3.1)
|--------------------------------------------------------------------------
|
| Covers the connection-state verbs that aren't exercised elsewhere:
|   - auth()  failure path (no password configured on either backend)
|   - select() to a valid DB, and to an out-of-range DB index (error path)
|   - closeConnection() / close() lifecycle teardown
|
| Plus the HELLO handshake reply (Group 3.2): StringsKeysExtraTest only
| asserts hello() is array-shaped; here we pin the actual key/value map the
| server returns (server/version/proto/role) on both engines.
|
| Things already covered and intentionally NOT duplicated here:
|   - ping/info/dbSize/time/flushDb/flushAll  -> ServerCommandsTest
|   - save/lastSave/role/config/acl/...        -> ServerAdminTest
|   - bgSave                                    -> MiscTier9Test
|   - echo + bare hello() array shape           -> StringsKeysExtraTest
|   - quit + reconnect-after-quit              -> QuitTest
|
| onMessage delivers a -ERR reply to the callback as $result === false with
| the wire message available via $client->error(); these tests assert that
| error-delivery contract rather than the exact (engine-specific) wording.
*/

it('auth surfaces an error when the server has no password set', function () {

    // Engine divergence: with no password configured, stock Redis rejects
    // `AUTH <pass>` with -ERR, but Dragonfly accepts it and replies +OK.
    // We gate on the OBSERVED reply rather than the backend name so this file
    // is correct however it's invoked (dragonfly/redis/unset env). We do NOT
    // reconfigure either server.
    $result = runInWorker(<<<'PHP'
        $redis->auth('definitely-not-the-password', function ($reply, $client) use ($emit) {
            $emit([
                'reply' => $reply,
                'error' => $client->error(),
            ]);
        });
    PHP);

    expect($result)->toBeArray();

    if ($result['reply'] !== false) {
        // Server accepted AUTH with no password set (Dragonfly behaviour) —
        // there is no error-delivery path to observe here.
        skipTest(
            '[' . currentBackend() . '] server accepted AUTH with no password set (reply '
            . var_export($result['reply'], true) . '); no -ERR rejection to assert'
        );
    }

    // Stock Redis answers `AUTH <pass>` (no requirepass) with an error reply.
    // Assert the client DELIVERS that error (reply === false + non-empty
    // error()) rather than hanging or treating it as success.
    expect($result['error'])->toBeString();
    expect($result['error'])->not->toBe('');
    // Both engines mention AUTH/password in the no-password rejection; accept
    // either wording without pinning the exact phrasing.
    $msg = strtolower($result['error']);
    $mentionsAuth = str_contains($msg, 'auth') || str_contains($msg, 'password');
    expect($mentionsAuth)->toBeTrue();
});

it('auth does not poison _auth after a rejected credential', function () {

    // The format closure in Client::auth() only records the credential on a
    // non-false reply. A rejected AUTH must leave the protected $_auth unset
    // so a later reconnect doesn't replay a bad credential. Verify via
    // reflection in-worker, after the AUTH callback has run. Gated on the
    // observed reply (see previous test): Dragonfly accepts AUTH with no
    // password set, so there is no rejection to observe there.
    $result = runInWorker(<<<'PHP'
        $redis->auth('still-not-the-password', function ($reply, $client) use ($emit) {
            $ref = new \ReflectionClass($client);
            $prop = $ref->getProperty('_auth');
            $prop->setAccessible(true);
            $emit([
                'reply'   => $reply,
                'authSet' => $prop->getValue($client),
            ]);
        });
    PHP);

    expect($result)->toBeArray();

    if ($result['reply'] !== false) {
        skipTest(
            '[' . currentBackend() . '] server accepted AUTH with no password set; no rejected credential to observe'
        );
    }

    // _auth defaults to null/false and must stay falsy after a rejected AUTH.
    expect($result['authSet'])->toBeFalsy();
});

it('select switches to a valid database and records it', function () {

    // SELECT 1 is valid on both Redis (16 DBs default) and Dragonfly. The
    // format closure records the new DB only on success, so we also confirm
    // the protected $_db tracker followed the switch.
    $result = runInWorker(<<<'PHP'
        $redis->select(1, function ($reply, $client) use ($emit) {
            $ref = new \ReflectionClass($client);
            $prop = $ref->getProperty('_db');
            $prop->setAccessible(true);
            $emit([
                'reply' => $reply,
                'db'    => $prop->getValue($client),
            ]);
        });
    PHP);

    expect($result)->toBeArray();
    // SELECT replies +OK, which onMessage normalises to boolean true.
    expect($result['reply'])->toBeTrue();
    expect((int) $result['db'])->toBe(1);
});

it('select to an out-of-range db index surfaces an error and leaves _db unchanged', function () {

    // SELECT 9999 is out of range on BOTH engines (-ERR "DB index is out of
    // range"). The client must deliver reply === false with a non-empty
    // error(), and Client::select()'s format closure must NOT advance $_db (so
    // a reconnect won't replay a DB the server rejected).
    $result = runInWorker(<<<'PHP'
        // Land on DB 2 first so we have a known-good baseline to prove the
        // failed SELECT did not move us.
        $redis->select(2, function () use ($redis, $emit) {
            $redis->select(9999, function ($reply, $client) use ($emit) {
                $ref = new \ReflectionClass($client);
                $prop = $ref->getProperty('_db');
                $prop->setAccessible(true);
                $emit([
                    'reply' => $reply,
                    'error' => $client->error(),
                    'db'    => $prop->getValue($client),
                ]);
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['reply'])->toBeFalse();
    expect($result['error'])->not->toBe('');
    // _db must still reflect the last SUCCESSFUL select (2), not 9999.
    expect((int) $result['db'])->toBe(2);
});

it('closeConnection tears down the socket and nulls the connection', function () {

    // closeConnection() detaches the AsyncTcpConnection handlers, closes the
    // socket, and nulls $_connection. Unlike quit(), it does NOT set
    // $_quitting. Issue a command first to prove the link was live, then close
    // and inspect the protected state via reflection.
    $result = runInWorker(<<<'PHP'
        $redis->ping(function ($reply, $client) use ($emit) {
            $client->closeConnection();
            \Workerman\Timer::add(0.3, function () use ($client, $reply, $emit) {
                $ref = new \ReflectionClass($client);
                $connProp = $ref->getProperty('_connection');
                $connProp->setAccessible(true);
                $emit([
                    'pong'       => $reply,
                    'connIsNull' => $connProp->getValue($client) === null,
                ]);
            }, [], false);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['pong'])->toBe('PONG');
    expect($result['connIsNull'])->toBeTrue();
});

it('hello returns a handshake map with server/version/proto fields', function () {

    // HELLO replies with a RESP map that onMessage delivers as a flat
    // sequential array of [k, v, k, v, ...]. We fold it into an associative
    // map and assert the handshake fields both engines populate. Stock Redis
    // reports server=redis/version=8.x; Dragonfly reports server=redis with a
    // separate dragonfly_version key — so we pin the shared fields (server,
    // version, proto, mode, role) and tolerate the engine-specific extras.
    $result = runInWorker(<<<'PHP'
        $redis->hello(2, function ($reply) use ($emit) {
            // Fold flat [k,v,...] pairs into a map. Skip non-scalar values
            // (e.g. the RESP2 `modules` list nested under its key) so the
            // top-level handshake fields fold cleanly.
            $map = [];
            for ($i = 0; $i + 1 < count($reply); $i += 2) {
                $k = $reply[$i];
                $v = $reply[$i + 1];
                if (is_string($k)) {
                    $map[$k] = $v;
                }
            }
            $emit(['raw' => $reply, 'map' => $map]);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['raw'])->toBeArray();
    $map = $result['map'];
    // Shared handshake fields present on both Redis and Dragonfly.
    expect($map)->toHaveKey('server');
    expect($map)->toHaveKey('version');
    expect($map)->toHaveKey('proto');
    expect($map)->toHaveKey('role');
    // Both engines identify the wire protocol family as "redis".
    expect($map['server'])->toBe('redis');
    // We asked for RESP2; the server echoes the negotiated protocol version.
    expect((int) $map['proto'])->toBe(2);
    // A standalone server reports the master role.
    expect($map['role'])->toBe('master');
    // version is a non-empty bulk string (e.g. "8.8.0" / "7.4.0").
    expect($map['version'])->toBeString();
    expect($map['version'])->not->toBe('');
});

it('close empties the queue and releases the connection', function () {

    // close() calls closeConnection() and additionally clears $_queue and the
    // wait-timeout timer. After close() the queue must be empty and the
    // connection gone.
    $result = runInWorker(<<<'PHP'
        $redis->ping(function ($reply, $client) use ($emit) {
            $client->close();
            \Workerman\Timer::add(0.3, function () use ($client, $reply, $emit) {
                $ref = new \ReflectionClass($client);
                $connProp = $ref->getProperty('_connection');
                $connProp->setAccessible(true);
                $queueProp = $ref->getProperty('_queue');
                $queueProp->setAccessible(true);
                $emit([
                    'pong'       => $reply,
                    'connIsNull' => $connProp->getValue($client) === null,
                    'queueEmpty' => $queueProp->getValue($client) === [],
                ]);
            }, [], false);
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['pong'])->toBe('PONG');
    expect($result['connIsNull'])->toBeTrue();
    expect($result['queueEmpty'])->toBeTrue();
});
