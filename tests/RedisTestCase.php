<?php

namespace Tests;

/**
 * Base class for integration tests that need to drive a Workerman event loop.
 *
 * Workerman's Worker::runAll() takes over the process — it forks, installs
 * signal handlers, and eventually calls exit(), which makes it impossible to
 * run multiple commands cleanly inside a single Pest process. The pragmatic
 * fix is to push each integration assertion into its own short-lived PHP
 * child via runInWorker(), which spawns tests/Support/run-in-worker.php and
 * passes the test snippet on stdin.
 *
 * Inside the snippet $redis is a connected Workerman\Redis\Client, and
 * $emit($value) / $fail($msg) report back via stdout. The snippet should
 * call $emit() from its callback to send the result back to the parent.
 *
 * Each runInWorker() call is a fresh child process — no state leaks between
 * tests, and a hang in one assertion can't poison the next.
 */
abstract class RedisTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipWithoutRedis();
    }

    /**
     * Execute $snippet inside a child PHP process with Workerman initialized.
     *
     * The snippet is plain PHP code (no <?php tag). It runs in a scope that
     * already has $redis, $emit, and $fail defined. To return a value to the
     * test, call $emit($value). Anything passable to json_encode() works.
     *
     * @param  string  $snippet   PHP source to execute. Will be eval'd inside
     *                            the child after the Workerman runtime is up.
     * @param  int     $timeout   Seconds before the child self-aborts. Default 5.
     * @return mixed              The value passed to $emit() in the snippet.
     */
    public function runInWorker(string $snippet, int $timeout = 5)
    {
        $runner = realpath(__DIR__ . '/Support/run-in-worker.php');
        $env = [
            'REDIS_URL' => $this->redisUrl(),
            'REDIS_TEST_TIMEOUT' => (string)$timeout,
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
            $this->fail('Could not spawn run-in-worker child');
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

        $firstLine = strtok($resultLine, "\n");
        if ($firstLine === false) {
            $this->fail("Child produced no result. exit={$exitCode} stdout={$stdout} stderr={$stderr}");
        }
        if (str_starts_with($firstLine, 'FAIL ')) {
            $this->fail('Worker child reported: ' . substr($firstLine, 5) . " stderr={$stderr}");
        }
        if (!str_starts_with($firstLine, 'OK ')) {
            $this->fail("Unexpected child result: {$firstLine} stderr={$stderr}");
        }
        return json_decode(substr($firstLine, 3), true);
    }
}
