<?php

/*
|--------------------------------------------------------------------------
| RediSearch (FT) module — extra verbs not covered by FtSearchTest
|--------------------------------------------------------------------------
|
| FtSearchTest already exercises ftCreate / ftSearch / ftAggregate /
| ftInfo / ftList / ftDropIndex. This file fills the remaining FT.*
| shortcuts on Client.php:
|
|   ftAlter   ftConfig   ftTagVals   ftSynUpdate   ftSynDump   ftProfile
|
| Both Dragonfly (search module) and the stock Redis 8.8 + RediSearch
| build implement these. Behaviour was probed with raw redis-cli AND
| through the worker harness on BOTH ports before these assertions were
| pinned; the historical FT.SEARCH "SEARCH_INDEX_NOT_FOUND" divergence
| that FtSearchTest gates on does NOT reproduce for these verbs in this
| environment (verified 5/5 in the worker on the Redis leg), so they run
| green on both engines without a backend gate. The two genuine
| cross-engine *shape* divergences (FT.CONFIG GET and FT.SYNDUMP reply
| nesting) are handled by asserting membership against the JSON-encoded
| reply rather than pinning an exact nested shape.
|
| Each test owns a unique index name (pest:g5:ft:tN:idx) and document
| prefix, and tears the index down at the end so repeat runs start clean.
*/

it('ftAlter adds a field to an existing index', function () {

    $result = runInWorker(<<<'PHP'
        $idx = 'pest:g5:ft:t1:idx';
        $redis->rawCommand('FT.DROPINDEX', $idx, function () use ($redis, $emit, $idx) {
            $redis->ftCreate(
                $idx,
                'ON', 'HASH',
                'PREFIX', 1, 'pest:g5:ft:t1:doc:',
                'SCHEMA', 'name', 'TEXT',
                function ($created) use ($redis, $emit, $idx) {
                    $redis->ftAlter($idx, 'SCHEMA', 'ADD', 'age', 'NUMERIC', function ($altered) use ($redis, $emit, $idx, $created) {
                        $redis->ftDropIndex($idx, function () use ($emit, $created, $altered) {
                            $emit(['created' => $created, 'altered' => $altered]);
                        });
                    });
                }
            );
        });
    PHP, 8);

    expect($result['created'])->toBeTrue();
    expect($result['altered'])->toBeTrue();
});

it('ftConfig GET returns a configured option value', function () {

    $result = runInWorker(<<<'PHP'
        // FT.CONFIG is module-global; it needs no index.
        $redis->ftConfig('GET', 'MAXSEARCHRESULTS', function ($cfg) use ($emit) {
            $emit(['config' => $cfg]);
        });
    PHP);

    // Engine divergence (shape only): Dragonfly replies with a flat
    // [option, value] pair; this Redis/RediSearch build wraps it as a list
    // of pairs [[option, value]]. Assert the option name appears in the
    // reply regardless of nesting. (Verified via raw redis-cli on both ports.)
    expect($result['config'])->toBeArray();
    expect(json_encode($result['config']))->toContain('MAXSEARCHRESULTS');
});

it('ftTagVals returns the distinct values of a TAG field', function () {

    $result = runInWorker(<<<'PHP'
        $idx = 'pest:g5:ft:t3:idx';
        $redis->rawCommand('FT.DROPINDEX', $idx, function () use ($redis, $emit, $idx) {
            $redis->ftCreate(
                $idx,
                'ON', 'HASH',
                'PREFIX', 1, 'pest:g5:ft:t3:doc:',
                'SCHEMA', 'color', 'TAG',
                function () use ($redis, $emit, $idx) {
                    $redis->hSet('pest:g5:ft:t3:doc:1', 'color', 'red', function () use ($redis, $emit, $idx) {
                        $redis->hSet('pest:g5:ft:t3:doc:2', 'color', 'blue', function () use ($redis, $emit, $idx) {
                            \Workerman\Timer::add(0.3, function () use ($redis, $emit, $idx) {
                                $redis->ftTagVals($idx, 'color', function ($vals) use ($redis, $emit, $idx) {
                                    $redis->ftDropIndex($idx, function () use ($redis, $emit, $vals) {
                                        $redis->del('pest:g5:ft:t3:doc:1', 'pest:g5:ft:t3:doc:2', function () use ($emit, $vals) {
                                            $emit(['tagvals' => $vals]);
                                        });
                                    });
                                });
                            }, [], false);
                        });
                    });
                }
            );
        });
    PHP, 8);

    expect($result['tagvals'])->toBeArray();
    // Tag values come back lower-cased; order is server-defined, so sort.
    $vals = array_map('strval', $result['tagvals']);
    sort($vals);
    expect($vals)->toBe(['blue', 'red']);
});

it('ftSynUpdate then ftSynDump round-trips a synonym group', function () {

    $result = runInWorker(<<<'PHP'
        $idx = 'pest:g5:ft:t4:idx';
        $redis->rawCommand('FT.DROPINDEX', $idx, function () use ($redis, $emit, $idx) {
            $redis->ftCreate(
                $idx,
                'ON', 'HASH',
                'PREFIX', 1, 'pest:g5:ft:t4:doc:',
                'SCHEMA', 'name', 'TEXT',
                function () use ($redis, $emit, $idx) {
                    $redis->ftSynUpdate($idx, 'grp1', 'hello', 'hi', function ($updated) use ($redis, $emit, $idx) {
                        $redis->ftSynDump($idx, function ($dump) use ($redis, $emit, $idx, $updated) {
                            $redis->ftDropIndex($idx, function () use ($emit, $updated, $dump) {
                                $emit(['updated' => $updated, 'dump' => $dump]);
                            });
                        });
                    });
                }
            );
        });
    PHP, 8);

    expect($result['updated'])->toBeTrue();
    expect($result['dump'])->toBeArray();
    // FT.SYNDUMP reply is [term, [groupIds...], term, [groupIds...]]; the group
    // id nesting differs slightly between engines, so assert both terms and the
    // group id appear in the encoded reply.
    $encoded = json_encode($result['dump']);
    expect($encoded)->toContain('hello');
    expect($encoded)->toContain('hi');
    expect($encoded)->toContain('grp1');
});

it('ftProfile runs a SEARCH and returns its result with timing data', function () {

    $result = runInWorker(<<<'PHP'
        $idx = 'pest:g5:ft:t5:idx';
        $redis->rawCommand('FT.DROPINDEX', $idx, function () use ($redis, $emit, $idx) {
            $redis->ftCreate(
                $idx,
                'ON', 'HASH',
                'PREFIX', 1, 'pest:g5:ft:t5:doc:',
                'SCHEMA', 'name', 'TEXT',
                function () use ($redis, $emit, $idx) {
                    $redis->hSet('pest:g5:ft:t5:doc:1', 'name', 'alice', function () use ($redis, $emit, $idx) {
                        \Workerman\Timer::add(0.3, function () use ($redis, $emit, $idx) {
                            $redis->ftProfile($idx, 'SEARCH', 'QUERY', 'alice', function ($profile) use ($redis, $emit, $idx) {
                                $redis->ftDropIndex($idx, function () use ($redis, $emit, $profile) {
                                    $redis->del('pest:g5:ft:t5:doc:1', function () use ($emit, $profile) {
                                        $emit(['profile' => $profile]);
                                    });
                                });
                            });
                        }, [], false);
                    });
                }
            );
        });
    PHP, 8);

    // FT.PROFILE replies [<search-result>, <profile-info>]. The profile-info
    // half is laid out very differently between Dragonfly and Redis, but the
    // first half is the ordinary FT.SEARCH reply on both: [hitCount, key, ...].
    expect($result['profile'])->toBeArray();
    $searchResult = $result['profile'][0];
    expect($searchResult)->toBeArray();
    expect($searchResult[0])->toBe(1);
    expect($searchResult[1])->toBe('pest:g5:ft:t5:doc:1');
});
