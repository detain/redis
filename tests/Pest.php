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
| REDIS_URL + REDIS_BACKEND env pair (see the Makefile targets). These
| free functions let an individual assertion react to which engine is
| under test — primarily to skip a case that is a *legitimate,
| documented* engine behavioural divergence (never a silent skip).
|
| Free functions (not RedisTestCase methods) for the same reason
| runInWorker() is: the project forbids the `@var $this` workaround in
| Pest closures on PHP 8.1 / phpstan 2.x.
|
*/

/**
 * The engine the current run targets, lower-cased.
 *
 * Set by the Makefile (`REDIS_BACKEND=dragonfly|redis`). Falls back to
 * 'unknown' when running pest directly without the env var.
 *
 * @return string e.g. 'dragonfly', 'redis', or 'unknown'
 */
function currentBackend(): string
{
    $backend = getenv('REDIS_BACKEND');

    return strtolower(($backend === false || $backend === '') ? 'unknown' : $backend);
}

/**
 * Skip the current test when it is running against $backend.
 *
 * Used to gate a single assertion that is a genuine engine divergence
 * (reply shape / format / semantics that differs between Redis and
 * Dragonfly and is NOT a bug in this client). The reason is surfaced in
 * the test output, prefixed with the backend, so skips are never silent.
 *
 * @param string $backend Engine to skip on ('redis' or 'dragonfly').
 * @param string $reason  Specific divergence being avoided.
 */
function skipOnBackend(string $backend, string $reason): void
{
    $backend = strtolower($backend);
    if (currentBackend() === $backend) {
        // Throw the same exception PHPUnit's markTestSkipped() raises. Using a
        // free function (not $this->markTestSkipped() / test()->...) keeps this
        // PHPStan-clean: test() returns a TestCall|HigherOrderTapProxy union on
        // which markTestSkipped() is not declared, and the project forbids
        // @var $this PHPDoc in Pest closures. The exception is the public,
        // documented skip mechanism and PHPUnit reports it as a skip with the
        // message, so the reason stays visible in the output.
        throw new \PHPUnit\Framework\SkippedWithMessageException("[{$backend}] {$reason}");
    }
}

/**
 * Skip the current test unconditionally with a reason.
 *
 * For divergences gated on OBSERVED runtime behaviour rather than the backend
 * name (e.g. "skip if the server accepted AUTH with no password set") — the
 * caller decides when to invoke it. Same PHPStan-clean exception mechanism as
 * skipOnBackend() (test()->markTestSkipped() trips method.notFound on Pest's
 * TestCall union, and the project forbids @var $this in closures).
 *
 * @param string $reason Why this case is being skipped; surfaced in output.
 */
function skipTest(string $reason): void
{
    throw new \PHPUnit\Framework\SkippedWithMessageException($reason);
}

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
    return runInWorkerScript(__DIR__ . '/Support/run-in-worker.php', $snippet, $timeout);
}

/**
 * Coroutine-mode sibling of runInWorker().
 *
 * Runs $snippet inside a child that boots Workerman on the Revolt-backed
 * `Workerman\Events\Fiber` driver (see Support/run-in-worker-coroutine.php).
 * Under that driver `Revolt\EventLoop` is loaded and onWorkerStart runs inside
 * a fiber, so commands the snippet issues WITHOUT a callback suspend the fiber
 * and RETURN their reply synchronously:
 *
 *     $redis->set('k', 'v');     // 'OK'
 *     $emit($redis->get('k'));   // emits 'v'
 *
 * Same fd-3 OK/FAIL protocol, coverage forwarding and env handling as
 * runInWorker(); a free function for the same no-`@var $this`-in-closures
 * reason documented on runInWorker().
 *
 * @param  string $snippet PHP code (no <?php) run after the worker boots, in a
 *                         fiber, with $redis/$emit/$fail in scope.
 * @param  int    $timeout Seconds before the child self-aborts.
 * @return mixed           The value passed to $emit().
 */
function runInCoroutineWorker(string $snippet, int $timeout = 5)
{
    return runInWorkerScript(__DIR__ . '/Support/run-in-worker-coroutine.php', $snippet, $timeout);
}

/**
 * Shared subprocess driver for runInWorker()/runInCoroutineWorker().
 *
 * Spawns $scriptPath via proc_open, feeds $snippet on stdin, forwards the
 * REDIS_URL/REDIS_BACKEND env and (when set) the COVERAGE_DIR + pcov ini so the
 * child can dump its own .cov, then reads the OK/FAIL result line off fd 3.
 *
 * @param  string $scriptPath Absolute path to the runner script.
 * @param  string $snippet    PHP code executed inside the subprocess.
 * @param  int    $timeout    Seconds before the child self-aborts.
 * @return mixed              The decoded value emitted by the child.
 */
function runInWorkerScript(string $scriptPath, string $snippet, int $timeout = 5)
{
    $runner = realpath($scriptPath);
    $env = [
        'REDIS_URL' => getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379',
        'REDIS_BACKEND' => getenv('REDIS_BACKEND') ?: '',
        'REDIS_TEST_TIMEOUT' => (string) $timeout,
        'PATH' => getenv('PATH'),
    ];

    // When COVERAGE_DIR is set (the merged-coverage pipeline, see
    // bin/run-coverage.sh) the child must collect its own coverage. pcov only
    // records when pcov.enabled=1, and that flag is PHP_INI_SYSTEM — it cannot
    // be turned on with ini_set() inside the child, so it has to be passed on
    // the command line here. We also forward COVERAGE_DIR so run-in-worker.php
    // knows where to dump its cov-<uniq>.cov file.
    $coverageDir = getenv('COVERAGE_DIR');
    $cmd = [PHP_BINARY];
    if (is_string($coverageDir) && $coverageDir !== '') {
        $env['COVERAGE_DIR'] = $coverageDir;
        $cmd[] = '-d';
        $cmd[] = 'pcov.enabled=1';
        $cmd[] = '-d';
        $cmd[] = 'pcov.directory=' . dirname(__DIR__) . '/src';
    }
    $cmd[] = $runner;
    $cmd[] = 'start';

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
        3 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
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
