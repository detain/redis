<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest binds every test closure to Tests\TestCase by default. Integration
| tests under tests/Feature inherit the redisUrl() / skipWithoutRedis()
| helpers; unit tests under tests/Unit use them only when they touch a
| real server.
|
*/

uses(\Tests\TestCase::class)->in('Unit', 'Feature');
