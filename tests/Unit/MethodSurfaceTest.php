<?php

use Workerman\Redis\Client;

/*
|--------------------------------------------------------------------------
| Method surface assertions
|--------------------------------------------------------------------------
|
| Pure reflection checks for explicit methods on Client whose live
| behaviour is either dangerous (SHUTDOWN tears the server down) or only
| meaningful against a specific server flavour (DIGEST is Dragonfly). The
| Feature/ suite exercises everything else end-to-end against the live
| server; this file just guards that the explicit-method declarations
| stay in place so __call() can't silently swallow them.
|
| Bound to Tests\TestCase (not RedisTestCase) so this file passes even
| when no Redis is reachable.
*/

it('shutdown is declared with the expected signature', function () {
    // hasMethod() goes through ReflectionClass instead of method_exists(),
    // which PHPStan would prove always-true (since Client is loaded at
    // analyse time). Reflection-based checks stay dynamic from PHPStan's
    // point of view but still catch a deletion or rename.
    $ref = new ReflectionClass(Client::class);
    expect($ref->hasMethod('shutdown'))->toBeTrue();

    $method = $ref->getMethod('shutdown');
    $params = $method->getParameters();
    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('mode');
    expect($params[0]->isOptional())->toBeTrue();
    expect($params[0]->getDefaultValue())->toBe('SAVE');
    expect($params[1]->getName())->toBe('cb');
    expect($params[1]->isOptional())->toBeTrue();
});

it('server admin dispatcher methods exist on Client', function () {
    $ref = new ReflectionClass(Client::class);
    foreach (['config', 'acl', 'slowLog', 'memory', 'command', 'cluster'] as $name) {
        expect($ref->hasMethod($name))->toBeTrue();
    }
});

it('no-arg server admin methods exist on Client', function () {
    $ref = new ReflectionClass(Client::class);
    foreach (['lastSave', 'save', 'role', 'digest', 'shutdown'] as $name) {
        expect($ref->hasMethod($name))->toBeTrue();
    }
});

it('unsubscribe family methods exist on Client', function () {
    // These bypass the subscribe-lock by writing straight to the socket, so
    // their behaviour is exercised live in Feature/UnsubscribeTest. Here we
    // just guard that the explicit declarations stay in place — __call() can't
    // reach them (the SUBSCRIBE lock would swallow a queued unsubscribe).
    $ref = new ReflectionClass(Client::class);
    foreach (['unsubscribe', 'pUnsubscribe', 'sUnsubscribe'] as $name) {
        expect($ref->hasMethod($name))->toBeTrue();
        expect($ref->getMethod($name)->isPublic())->toBeTrue();
    }
});
