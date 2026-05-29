<?php

use Workerman\Redis\Client;

/*
|--------------------------------------------------------------------------
| *ScanAll callback-mode aggregation (pure logic, no event loop, no server)
|--------------------------------------------------------------------------
|
| The four aggregation helpers (scanAll / hScanAll / sScanAll / zScanAll)
| have two branches:
|
|   1. Coroutine branch: `!$cb && class_exists(EventLoop::class)`. Revolt is
|      NOT installed in the Unit context, so this branch is unreachable here
|      (calling it with no $cb would also try to suspend a fiber). It is left
|      to Group 8.
|   2. Callback branch: the caller passes a $cb. This chains scan() calls via
|      a recursive `$step` closure. THIS is the pure aggregation logic and is
|      fully drivable without a socket:
|
|        - scanAll($opts, $cb) queues one SCAN entry whose stored callback is
|          $step and whose stored formatter is scan()'s reshaper.
|        - process() is inert ($_connection is null on a no-constructor client),
|          so nothing is sent.
|        - We simulate the server: pop the queued entry, run a crafted RAW RESP
|          reply through its formatter, then invoke $step with the formatted
|          result. $step accumulates and either re-queues the next SCAN (cursor
|          != '0' and limit not hit) or fires the user $cb.
|
| Feeding crafted cursor pages this way exercises: loop termination on
| cursor '0', accumulation across pages, the LIMIT cap, dedup, the
| non-array/error -> false abort path, and zScanAll score-string precision.
*/

function scanClient(): Client
{
    return (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
}

/**
 * @return array<int, mixed> the raw $_queue
 */
function scanQueueRaw(Client $client): array
{
    $prop = (new ReflectionClass(Client::class))->getProperty('_queue');
    $prop->setAccessible(true);

    return $prop->getValue($client);
}

function scanSetQueue(Client $client, array $queue): void
{
    $prop = (new ReflectionClass(Client::class))->getProperty('_queue');
    $prop->setAccessible(true);
    $prop->setValue($client, $queue);
}

/**
 * Drive a *ScanAll callback loop by feeding it crafted RAW RESP scan replies.
 *
 * @param Client            $client
 * @param array<int, mixed> $pages  Each page is the raw two-element reply
 *                                  [cursorString, flatArray] that a real
 *                                  SCAN/HSCAN/etc. would return, OR a
 *                                  non-array (e.g. false / an error string)
 *                                  to exercise the abort path.
 */
function scanPump(Client $client, array $pages): void
{
    foreach ($pages as $rawReply) {
        $queue = scanQueueRaw($client);
        // The pending scan entry is the most recently queued one.
        $entry = end($queue);
        if ($entry === false) {
            throw new RuntimeException('scanPump: no pending scan entry to feed');
        }
        $key = key($queue);
        // Consume it (a real send would have removed it from the head once the
        // reply arrived; the $step closure re-queues the next scan itself).
        unset($queue[$key]);
        scanSetQueue($client, $queue);

        $cb = $entry[2];
        $format = $entry[3] ?? null;
        $formatted = $format ? $format($rawReply) : $rawReply;
        $cb($formatted);
    }
}

// ---------------------------------------------------------------------------
// scanAll
// ---------------------------------------------------------------------------

it('scanAll accumulates keys across pages and terminates on cursor "0"', function () {
    $client = scanClient();
    $result = null;
    $client->scanAll([], function ($keys) use (&$result) {
        $result = $keys;
    });

    // Two pages: cursor "12" then "0".
    scanPump($client, [
        ['12', ['k1', 'k2']],
        ['0', ['k3']],
    ]);

    expect($result)->toBe(['k1', 'k2', 'k3']);
});

it('scanAll fires immediately on a single terminal page', function () {
    $client = scanClient();
    $result = null;
    $client->scanAll([], function ($keys) use (&$result) {
        $result = $keys;
    });

    scanPump($client, [['0', ['only']]]);

    expect($result)->toBe(['only']);
});

it('scanAll honours the LIMIT cap and stops mid-page', function () {
    $client = scanClient();
    $result = null;
    $client->scanAll(['LIMIT' => 2], function ($keys) use (&$result) {
        $result = $keys;
    });

    // First page already overflows the limit of 2; loop must stop and never
    // pull the second page.
    scanPump($client, [['99', ['a', 'b', 'c']]]);

    expect($result)->toBe(['a', 'b']);
    // No further scan was queued (we stopped at the cap, not at cursor 0).
    expect(scanQueueRaw($client))->toBe([]);
});

it('scanAll hands the callback false when scan() yields a non-array (error) reply', function () {
    $client = scanClient();
    $result = 'untouched';
    $client->scanAll([], function ($reply) use (&$result) {
        $result = $reply;
    });

    // Simulate an error reply: scan()'s formatter passes non-arrays through
    // unchanged, so $step sees a non-array and aborts with false.
    scanPump($client, ['ERR some failure']);

    expect($result)->toBeFalse();
});

it('scanAll forwards MATCH/COUNT/TYPE into the first scan args and drops LIMIT', function () {
    $client = scanClient();
    $client->scanAll(['MATCH' => 'user:*', 'COUNT' => 100, 'LIMIT' => 5, 'TYPE' => 'string'], function () {});

    $queue = scanQueueRaw($client);
    $args = $queue[array_key_first($queue)][0];
    // First element is SCAN, second is the '0' start cursor, then the options.
    expect($args[0])->toBe('SCAN');
    expect($args[1])->toBe('0');
    // LIMIT is a client-side cap and must NOT appear on the wire.
    expect(in_array('LIMIT', $args, true))->toBeFalse();
    expect(in_array('MATCH', $args, true))->toBeTrue();
    expect(in_array('COUNT', $args, true))->toBeTrue();
    expect(in_array('TYPE', $args, true))->toBeTrue();
});

// ---------------------------------------------------------------------------
// hScanAll
// ---------------------------------------------------------------------------

it('hScanAll merges field=>value pairs across pages into one map', function () {
    $client = scanClient();
    $result = null;
    $client->hScanAll('h', [], function ($fields) use (&$result) {
        $result = $fields;
    });

    scanPump($client, [
        ['7', ['f1', 'v1', 'f2', 'v2']],
        ['0', ['f3', 'v3']],
    ]);

    expect($result)->toBe(['f1' => 'v1', 'f2' => 'v2', 'f3' => 'v3']);
});

it('hScanAll lets a later page overwrite a re-yielded field (rehash safety)', function () {
    $client = scanClient();
    $result = null;
    $client->hScanAll('h', [], function ($fields) use (&$result) {
        $result = $fields;
    });

    scanPump($client, [
        ['4', ['dup', 'old']],
        ['0', ['dup', 'new', 'other', 'x']],
    ]);

    expect($result)->toBe(['dup' => 'new', 'other' => 'x']);
});

// ---------------------------------------------------------------------------
// sScanAll
// ---------------------------------------------------------------------------

it('sScanAll dedupes members revisited during a rehash and returns a flat list', function () {
    $client = scanClient();
    $result = null;
    $client->sScanAll('s', [], function ($members) use (&$result) {
        $result = $members;
    });

    scanPump($client, [
        ['3', ['m1', 'm2']],
        ['0', ['m2', 'm3']],   // m2 repeated
    ]);

    expect($result)->toBe(['m1', 'm2', 'm3']);
});

it('sScanAll caps at LIMIT and returns array_values (a flat list)', function () {
    $client = scanClient();
    $result = null;
    $client->sScanAll('s', ['LIMIT' => 2], function ($members) use (&$result) {
        $result = $members;
    });

    scanPump($client, [['8', ['x', 'y', 'z']]]);

    expect($result)->toBe(['x', 'y']);
});

// ---------------------------------------------------------------------------
// zScanAll
// ---------------------------------------------------------------------------

it('zScanAll merges member=>score pairs and keeps scores as raw strings (precision)', function () {
    $client = scanClient();
    $result = null;
    $client->zScanAll('z', [], function ($members) use (&$result) {
        $result = $members;
    });

    // A score that would lose precision if cast to float.
    scanPump($client, [
        ['5', ['alpha', '3.0000000000000004', 'beta', '2']],
        ['0', ['gamma', '1.5']],
    ]);

    expect($result)->toBe([
        'alpha' => '3.0000000000000004',
        'beta' => '2',
        'gamma' => '1.5',
    ]);
    // Scores stay strings — never coerced to float.
    expect($result['alpha'])->toBeString();
});

it('zScanAll aborts to false on an error reply', function () {
    $client = scanClient();
    $result = 'untouched';
    $client->zScanAll('z', [], function ($reply) use (&$result) {
        $result = $reply;
    });

    scanPump($client, ['WRONGTYPE Operation against a key holding the wrong kind of value']);

    expect($result)->toBeFalse();
});
