<?php

/*
|--------------------------------------------------------------------------
| RedisJSON / JSON.* module
|--------------------------------------------------------------------------
|
| Dragonfly natively implements the RedisJSON command set. These tests
| exercise the json() dispatcher and the typed shortcuts (jsonSet,
| jsonGet, jsonArrAppend, …) added to Client.php.
|
| Each test uses a unique pest:json:tN: prefix so concurrent runs don't
| collide. JSON values go on the wire as JSON-encoded strings; the
| format-callback layer does not decode them, so assertions json_decode
| the reply where they need a PHP array.
*/

it('json dispatcher works for arbitrary subcommand', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t1:doc', function () use ($redis, $emit) {
            // SET via the raw dispatcher (no shortcut method).
            $redis->json('SET', 'pest:json:t1:doc', '$', '{"hello":"world"}', function ($ok) use ($redis, $emit) {
                $redis->json('GET', 'pest:json:t1:doc', function ($reply) use ($ok, $emit) {
                    $emit(['ok' => $ok, 'doc' => $reply]);
                });
            });
        });
    PHP);

    expect($result)->toBeArray();
    expect($result['ok'])->toBeTrue();
    expect(json_decode($result['doc'], true))->toBe(['hello' => 'world']);
});

it('jsonSet and jsonGet round-trip a document', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t2:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t2:doc', '$', '{"name":"alice","age":30}', function ($ok) use ($redis, $emit) {
                $redis->jsonGet('pest:json:t2:doc', function ($reply) use ($ok, $emit) {
                    $emit(['ok' => $ok, 'doc' => $reply]);
                });
            });
        });
    PHP);

    expect($result['ok'])->toBeTrue();
    // Dragonfly serialises object keys alphabetically, so compare unordered.
    expect(json_decode($result['doc'], true))->toEqualCanonicalizing(['name' => 'alice', 'age' => 30]);
});

it('jsonGet with multiple paths returns a path-keyed object', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t3:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t3:doc', '$', '{"a":1,"b":"hi","c":[1,2,3]}', function () use ($redis, $emit) {
                $redis->jsonGet('pest:json:t3:doc', '$.a', '$.b', function ($reply) use ($emit) {
                    $emit(['doc' => $reply]);
                });
            });
        });
    PHP);

    $decoded = json_decode($result['doc'], true);
    expect($decoded)->toBeArray();
    // Dragonfly returns multi-path GET as {"$.a":[1],"$.b":["hi"]}.
    expect($decoded)->toHaveKey('$.a');
    expect($decoded)->toHaveKey('$.b');
    expect($decoded['$.a'])->toBe([1]);
    expect($decoded['$.b'])->toBe(['hi']);
});

it('jsonDel removes a path', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t4:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t4:doc', '$', '{"keep":1,"drop":2}', function () use ($redis, $emit) {
                $redis->jsonDel('pest:json:t4:doc', '$.drop', function ($removed) use ($redis, $emit) {
                    $redis->jsonGet('pest:json:t4:doc', function ($reply) use ($removed, $emit) {
                        $emit(['removed' => $removed, 'doc' => $reply]);
                    });
                });
            });
        });
    PHP);

    expect($result['removed'])->toBe(1);
    expect(json_decode($result['doc'], true))->toBe(['keep' => 1]);
});

it('jsonForget removes a path (alias of DEL)', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t5:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t5:doc', '$', '{"keep":1,"drop":2}', function () use ($redis, $emit) {
                $redis->jsonForget('pest:json:t5:doc', '$.drop', function ($removed) use ($redis, $emit) {
                    $redis->jsonGet('pest:json:t5:doc', function ($reply) use ($removed, $emit) {
                        $emit(['removed' => $removed, 'doc' => $reply]);
                    });
                });
            });
        });
    PHP);

    expect($result['removed'])->toBe(1);
    expect(json_decode($result['doc'], true))->toBe(['keep' => 1]);
});

it('jsonMGet returns aligned values across multiple keys', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t6:a', 'pest:json:t6:b', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t6:a', '$', '{"v":1}', function () use ($redis, $emit) {
                $redis->jsonSet('pest:json:t6:b', '$', '{"v":2}', function () use ($redis, $emit) {
                    $redis->jsonMGet(['pest:json:t6:a', 'pest:json:t6:b'], '$.v', function ($reply) use ($emit) {
                        $emit(['reply' => $reply]);
                    });
                });
            });
        });
    PHP);

    expect($result['reply'])->toBeArray();
    expect($result['reply'])->toHaveCount(2);
    expect(json_decode($result['reply'][0], true))->toBe([1]);
    expect(json_decode($result['reply'][1], true))->toBe([2]);
});

it('jsonMSet stores multiple docs atomically', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t7:a', 'pest:json:t7:b', function () use ($redis, $emit) {
            $tuples = [
                ['pest:json:t7:a', '$', '{"q":1}'],
                ['pest:json:t7:b', '$', '{"q":2}'],
            ];
            $redis->jsonMSet($tuples, function ($ok) use ($redis, $emit) {
                $redis->jsonGet('pest:json:t7:a', function ($a) use ($redis, $ok, $emit) {
                    $redis->jsonGet('pest:json:t7:b', function ($b) use ($ok, $a, $emit) {
                        $emit(['ok' => $ok, 'a' => $a, 'b' => $b]);
                    });
                });
            });
        });
    PHP);

    expect($result['ok'])->toBeTrue();
    expect(json_decode($result['a'], true))->toBe(['q' => 1]);
    expect(json_decode($result['b'], true))->toBe(['q' => 2]);
});

it('jsonMerge merges into an existing doc', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t8:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t8:doc', '$', '{"a":1,"b":2}', function () use ($redis, $emit) {
                $redis->jsonMerge('pest:json:t8:doc', '$', '{"b":99,"c":3}', function ($ok) use ($redis, $emit) {
                    $redis->jsonGet('pest:json:t8:doc', function ($reply) use ($ok, $emit) {
                        $emit(['ok' => $ok, 'doc' => $reply]);
                    });
                });
            });
        });
    PHP);

    expect($result['ok'])->toBeTrue();
    $decoded = json_decode($result['doc'], true);
    expect($decoded)->toMatchArray(['a' => 1, 'b' => 99, 'c' => 3]);
});

it('jsonArrAppend appends to an array element', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t9:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t9:doc', '$', '{"tags":["a","b"]}', function () use ($redis, $emit) {
                $redis->jsonArrAppend('pest:json:t9:doc', '$.tags', '"c"', '"d"', function ($lengths) use ($redis, $emit) {
                    $redis->jsonGet('pest:json:t9:doc', '$.tags', function ($reply) use ($lengths, $emit) {
                        $emit(['lengths' => $lengths, 'tags' => $reply]);
                    });
                });
            });
        });
    PHP);

    // jsonArrAppend returns an array of new lengths per matched path.
    expect($result['lengths'])->toBe([4]);
    expect(json_decode($result['tags'], true))->toBe([['a', 'b', 'c', 'd']]);
});

it('jsonArrLen returns array length', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t10:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t10:doc', '$', '{"xs":[1,2,3,4,5]}', function () use ($redis, $emit) {
                $redis->jsonArrLen('pest:json:t10:doc', '$.xs', function ($reply) use ($emit) {
                    $emit(['len' => $reply]);
                });
            });
        });
    PHP);

    expect($result['len'])->toBe([5]);
});

it('jsonObjKeys lists object keys', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t11:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t11:doc', '$', '{"a":1,"b":2,"c":3}', function () use ($redis, $emit) {
                $redis->jsonObjKeys('pest:json:t11:doc', '$', function ($reply) use ($emit) {
                    $emit(['keys' => $reply]);
                });
            });
        });
    PHP);

    // OBJKEYS over a JSONPath returns an array of arrays (one per match).
    expect($result['keys'])->toBeArray();
    expect($result['keys'])->toHaveCount(1);
    $keys = $result['keys'][0];
    sort($keys);
    expect($keys)->toBe(['a', 'b', 'c']);
});

it('jsonObjLen returns object key count', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t12:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t12:doc', '$', '{"a":1,"b":2,"c":3,"d":4}', function () use ($redis, $emit) {
                $redis->jsonObjLen('pest:json:t12:doc', '$', function ($reply) use ($emit) {
                    $emit(['len' => $reply]);
                });
            });
        });
    PHP);

    expect($result['len'])->toBe([4]);
});

it('jsonType returns the JSON type', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t13:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t13:doc', '$', '{"n":42,"s":"hi","arr":[1,2]}', function () use ($redis, $emit) {
                $redis->jsonType('pest:json:t13:doc', '$.n', function ($numType) use ($redis, $emit) {
                    $redis->jsonType('pest:json:t13:doc', '$.s', function ($strType) use ($redis, $numType, $emit) {
                        $redis->jsonType('pest:json:t13:doc', '$.arr', function ($arrType) use ($numType, $strType, $emit) {
                            $emit(['n' => $numType, 's' => $strType, 'arr' => $arrType]);
                        });
                    });
                });
            });
        });
    PHP);

    // JSONPath form returns an array of type names, one per match.
    expect($result['n'])->toBe(['integer']);
    expect($result['s'])->toBe(['string']);
    expect($result['arr'])->toBe(['array']);
});

it('jsonNumIncrBy increments a number', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t14:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t14:doc', '$', '{"n":10}', function () use ($redis, $emit) {
                $redis->jsonNumIncrBy('pest:json:t14:doc', '$.n', 7, function ($reply) use ($redis, $emit) {
                    $redis->jsonGet('pest:json:t14:doc', '$.n', function ($doc) use ($reply, $emit) {
                        $emit(['inc' => $reply, 'final' => $doc]);
                    });
                });
            });
        });
    PHP);

    // The increment reply is a JSON-encoded array of new values.
    expect(json_decode($result['inc'], true))->toBe([17]);
    expect(json_decode($result['final'], true))->toBe([17]);
});

it('jsonStrAppend appends to a string', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t15:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t15:doc', '$', '{"s":"hi"}', function () use ($redis, $emit) {
                // RedisJSON STRAPPEND expects a JSON-encoded string literal.
                $redis->jsonStrAppend('pest:json:t15:doc', '$.s', '"!"', function ($lengths) use ($redis, $emit) {
                    $redis->jsonGet('pest:json:t15:doc', '$.s', function ($reply) use ($lengths, $emit) {
                        $emit(['lengths' => $lengths, 's' => $reply]);
                    });
                });
            });
        });
    PHP);

    expect($result['lengths'])->toBe([3]);
    expect(json_decode($result['s'], true))->toBe(['hi!']);
});

it('jsonStrLen returns string length', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t16:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t16:doc', '$', '{"s":"hello"}', function () use ($redis, $emit) {
                $redis->jsonStrLen('pest:json:t16:doc', '$.s', function ($reply) use ($emit) {
                    $emit(['len' => $reply]);
                });
            });
        });
    PHP);

    expect($result['len'])->toBe([5]);
});

it('jsonToggle toggles a boolean', function () {

    $result = runInWorker(<<<'PHP'
        $redis->del('pest:json:t17:doc', function () use ($redis, $emit) {
            $redis->jsonSet('pest:json:t17:doc', '$', '{"flag":true}', function () use ($redis, $emit) {
                $redis->jsonToggle('pest:json:t17:doc', '$.flag', function ($toggleReply) use ($redis, $emit) {
                    $redis->jsonGet('pest:json:t17:doc', '$.flag', function ($reply) use ($toggleReply, $emit) {
                        $emit(['toggle' => $toggleReply, 'flag' => $reply]);
                    });
                });
            });
        });
    PHP);

    // After toggling true -> false. Toggle reply is an array of new states (0/1).
    expect($result['toggle'])->toBe([0]);
    expect(json_decode($result['flag'], true))->toBe([false]);
});
