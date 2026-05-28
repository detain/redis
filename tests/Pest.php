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
| runInWorker helper
|--------------------------------------------------------------------------
|
| Executes $snippet inside a child PHP process with the Workerman runtime
| initialised. The snippet runs in a scope where $redis is a connected
| Workerman\Redis\Client, and $emit($value) / $fail($msg) report back
| to the parent via an out-of-band pipe (fd 3) so they don't collide
| with Workerman's startup banner on stdout.
|
| Exposed as a free function instead of a method on RedisTestCase so
| PHPStan doesn't have to reason about $this inside Pest's bound test
| closures — on PHP 8.1 / phpstan 2.x the `@var $this` workaround
| produces "Variable $this in PHPDoc tag @var does not match assigned
| variable $result" errors. Free function side-steps that entirely.
|
| @param  string  $snippet  PHP code (no <?php tag) executed inside the
|                           subprocess after the worker boots. Has
|                           $redis, $emit, $fail in scope.
| @param  int     $timeout  Seconds before the child self-aborts.
| @return mixed             The value passed to $emit() — anything
|                           json_encode()-able.
*/

function runInWorker(string $snippet, int $timeout = 5)
{
    $runner = realpath(__DIR__ . '/Support/run-in-worker.php');
    $env = [
        'REDIS_URL' => getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379',
        'REDIS_TEST_TIMEOUT' => (string) $timeout,
        'PATH' => getenv('PATH'),
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
        3 => ['pipe', 'w'],
    ];
    $proc = proc_open([PHP_BINARY, $runner, 'start'], $descriptors, $pipes, null, $env);
    if (!\is_resource($proc)) {
        throw new \RuntimeException('Could not spawn run-in-worker child');
    }
    fwrite($pipes[0], $snippet);
    fclose($pipes[0]);
    $resultLine = stream_get_contents($pipes[3]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    fclose($pipes[3]);
    $exitCode = proc_close($proc);

    $firstLine = strtok((string) $resultLine, "\n");
    if ($firstLine === false) {
        throw new \RuntimeException("Child produced no result. exit={$exitCode} stdout={$stdout} stderr={$stderr}");
    }
    if (str_starts_with($firstLine, 'FAIL ')) {
        throw new \RuntimeException('Worker child reported: ' . substr($firstLine, 5) . " stderr={$stderr}");
    }
    if (!str_starts_with($firstLine, 'OK ')) {
        throw new \RuntimeException("Unexpected child result: {$firstLine} stderr={$stderr}");
    }
    return json_decode(substr($firstLine, 3), true);
}
