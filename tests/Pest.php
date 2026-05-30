<?php

/*
|--------------------------------------------------------------------------
| Test Case binding
|--------------------------------------------------------------------------
|
| Unit tests bind to Tests\TestCase. Feature tests bind to
| Tests\RedisTestCase, which skips on a missing Redis in its setUp().
|
*/

uses(\Tests\TestCase::class)->in('Unit');
uses(\Tests\RedisTestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Backend awareness (dual-engine testing: Dragonfly + Redis)
|--------------------------------------------------------------------------
|
| The same suite runs against both engines, selected per-run by the
| REDIS_URL + REDIS_BACKEND env pair (see the Makefile targets). The free
| helper functions that react to which engine is under test
| (currentBackend, skipOnBackend, skipTest, runInWorker,
| runInCoroutineWorker, runInWorkerScript) now live in tests/helpers.php,
| autoloaded via composer's autoload-dev.files. They were moved out of here
| to avoid a redeclaration fatal once helpers.php is on the autoload path.
|
*/
