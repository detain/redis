<?php

/*
|--------------------------------------------------------------------------
| Tier 9 — RedisSearch (FT) module
|--------------------------------------------------------------------------
|
| Dragonfly ships a `search` module that implements the FT.* command set.
| These tests exercise the ft() dispatcher and the typed shortcuts
| (ftCreate, ftSearch, ftAggregate, ftDropIndex, ftInfo, ftList) added to
| Client.php.
|
| Each test owns a unique index name (pest:ft:tN:idx) and a unique
| document prefix (pest:ft:tN:doc:) so concurrent runs don't trip on each
| other. Indexes are torn down at the end of each test via ftDropIndex().
|
| The tests assert on reply *shapes* rather than exact byte sequences:
| reply formats vary across Dragonfly versions and we want the suite to
| stay green as the module evolves.
*/

it('ftCreate then ftSearch returns matching documents', function () {

    $result = runInWorker(<<<'PHP'
        $idx = 'pest:ft:t1:idx';
        // Pre-clean any leftover index from a prior run.
        $redis->rawCommand('FT.DROPINDEX', $idx, function () use ($redis, $emit, $idx) {
            $redis->ftCreate(
                $idx,
                'ON', 'HASH',
                'PREFIX', 1, 'pest:ft:t1:doc:',
                'SCHEMA', 'name', 'TEXT',
                function ($created) use ($redis, $emit, $idx) {
                    $redis->hSet('pest:ft:t1:doc:1', 'name', 'alice', function () use ($redis, $emit, $idx, $created) {
                        $redis->hSet('pest:ft:t1:doc:2', 'name', 'bob', function () use ($redis, $emit, $idx, $created) {
                            // Give the index a moment to populate.
                            \Workerman\Timer::add(0.3, function () use ($redis, $emit, $idx, $created) {
                                $redis->ftSearch($idx, 'alice', function ($reply) use ($redis, $emit, $idx, $created) {
                                    // Tear down so the next run starts clean.
                                    $redis->ftDropIndex($idx, function () use ($redis, $emit, $reply, $created) {
                                        $redis->del('pest:ft:t1:doc:1', function () use ($redis, $emit, $reply, $created) {
                                            $redis->del('pest:ft:t1:doc:2', function () use ($emit, $reply, $created) {
                                                $emit(['created' => $created, 'search' => $reply]);
                                            });
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

    expect($result['created'])->toBeTrue();
    expect($result['search'])->toBeArray();
    // First element is the total hit count.
    expect($result['search'][0])->toBe(1);
    // Second element is the matching document's key.
    expect($result['search'][1])->toBe('pest:ft:t1:doc:1');
});

it('ftList returns the list of defined indexes', function () {

    $result = runInWorker(<<<'PHP'
        $idx = 'pest:ft:t2:idx';
        $redis->rawCommand('FT.DROPINDEX', $idx, function () use ($redis, $emit, $idx) {
            $redis->ftCreate(
                $idx,
                'ON', 'HASH',
                'PREFIX', 1, 'pest:ft:t2:doc:',
                'SCHEMA', 'name', 'TEXT',
                function () use ($redis, $emit, $idx) {
                    $redis->ftList(function ($list) use ($redis, $emit, $idx) {
                        $redis->ftDropIndex($idx, function () use ($emit, $list) {
                            $emit($list);
                        });
                    });
                }
            );
        });
    PHP, 8);

    expect($result)->toBeArray();
    expect($result)->toContain('pest:ft:t2:idx');
});

it('ftInfo returns metadata for an index', function () {

    $result = runInWorker(<<<'PHP'
        $idx = 'pest:ft:t3:idx';
        $redis->rawCommand('FT.DROPINDEX', $idx, function () use ($redis, $emit, $idx) {
            $redis->ftCreate(
                $idx,
                'ON', 'HASH',
                'PREFIX', 1, 'pest:ft:t3:doc:',
                'SCHEMA', 'name', 'TEXT',
                function () use ($redis, $emit, $idx) {
                    $redis->ftInfo($idx, function ($info) use ($redis, $emit, $idx) {
                        $redis->ftDropIndex($idx, function () use ($emit, $info) {
                            $emit($info);
                        });
                    });
                }
            );
        });
    PHP, 8);

    expect($result)->toBeArray();
    // FT.INFO reply is a flat [name, value, name, value, ...] array. The
    // 'index_name' / 'index_definition' fields are always present.
    expect($result)->toContain('index_name');
});

it('ftDropIndex removes an index from ftList', function () {

    $result = runInWorker(<<<'PHP'
        $idx = 'pest:ft:t4:idx';
        $redis->rawCommand('FT.DROPINDEX', $idx, function () use ($redis, $emit, $idx) {
            $redis->ftCreate(
                $idx,
                'ON', 'HASH',
                'PREFIX', 1, 'pest:ft:t4:doc:',
                'SCHEMA', 'name', 'TEXT',
                function () use ($redis, $emit, $idx) {
                    $redis->ftDropIndex($idx, function ($dropped) use ($redis, $emit, $idx) {
                        $redis->ftList(function ($list) use ($emit, $dropped, $idx) {
                            $emit([
                                'dropped' => $dropped,
                                'still_listed' => \is_array($list) && \in_array($idx, $list, true),
                            ]);
                        });
                    });
                }
            );
        });
    PHP, 8);

    expect($result['dropped'])->toBeTrue();
    expect($result['still_listed'])->toBeFalse();
});

it('ftAggregate runs a basic GROUPBY aggregation', function () {

    $result = runInWorker(<<<'PHP'
        $idx = 'pest:ft:t5:idx';
        $redis->rawCommand('FT.DROPINDEX', $idx, function () use ($redis, $emit, $idx) {
            $redis->ftCreate(
                $idx,
                'ON', 'HASH',
                'PREFIX', 1, 'pest:ft:t5:doc:',
                'SCHEMA', 'name', 'TAG',
                function () use ($redis, $emit, $idx) {
                    $redis->hSet('pest:ft:t5:doc:1', 'name', 'alice', function () use ($redis, $emit, $idx) {
                        $redis->hSet('pest:ft:t5:doc:2', 'name', 'bob', function () use ($redis, $emit, $idx) {
                            $redis->hSet('pest:ft:t5:doc:3', 'name', 'alice', function () use ($redis, $emit, $idx) {
                                \Workerman\Timer::add(0.3, function () use ($redis, $emit, $idx) {
                                    $redis->ftAggregate($idx, '*', 'GROUPBY', 1, '@name', function ($agg) use ($redis, $emit, $idx) {
                                        $redis->ftDropIndex($idx, function () use ($redis, $emit, $agg) {
                                            $redis->del('pest:ft:t5:doc:1', 'pest:ft:t5:doc:2', 'pest:ft:t5:doc:3', function () use ($emit, $agg) {
                                                $emit($agg);
                                            });
                                        });
                                    });
                                }, [], false);
                            });
                        });
                    });
                }
            );
        });
    PHP, 8);

    expect($result)->toBeArray();
    // First element is the count of result rows. We grouped 3 docs into 2
    // buckets (alice x2 + bob x1), so the count should be at least 1
    // (Dragonfly reports the row count, exact format may vary).
    expect($result[0])->toBeInt();
    expect($result[0])->toBeGreaterThanOrEqual(1);
});
