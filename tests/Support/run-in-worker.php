<?php
/**
 * Subprocess runner used by integration tests.
 *
 * Each integration test invokes this script via proc_open with a small PHP
 * snippet on stdin. The snippet has access to:
 *   - $redis  (Workerman\Redis\Client connected to REDIS_URL env)
 *   - $emit(mixed $value)  callable that writes the value as a JSON line to
 *                          file descriptor 3 (the parent-readable pipe) and
 *                          stops the event loop
 *   - $fail(string $msg)   callable that writes FAIL to fd 3 and stops
 *
 * Why a separate file descriptor (fd 3) instead of stdout? Workerman prints
 * its own start-up banner on stdout when runAll() boots ("start in DEBUG
 * mode", etc.). Mixing that into the result protocol is fragile. The parent
 * proc_open passes an extra pipe at index 3 which the runner reuses as a
 * clean channel for the test result.
 *
 * Per-invocation, the runner also forces unique Worker::$pidFile and
 * Worker::$logFile paths under sys_get_temp_dir() — otherwise repeat runs
 * collide on the cached pid file and exit with "already running".
 *
 * Wire format on fd 3:
 *   OK <json-value>\n
 *   FAIL <message>\n
 *
 * Timeout: REDIS_TEST_TIMEOUT env var (default 5 seconds).
 */

require __DIR__ . '/../../vendor/autoload.php';

use Workerman\Redis\Client;
use Workerman\Worker;
use Workerman\Timer;

$snippet = stream_get_contents(STDIN);
if ($snippet === false || $snippet === '') {
    fwrite(STDERR, "no snippet on stdin\n");
    exit(2);
}

$resultFd = fopen('php://fd/3', 'w');
if (!$resultFd) {
    // Fall back to stdout if fd 3 isn't available — parent must filter.
    $resultFd = STDOUT;
}

$redisUrl = getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379';
$timeoutSeconds = (int)(getenv('REDIS_TEST_TIMEOUT') ?: 5);

$uniq = bin2hex(random_bytes(6));
Worker::$pidFile = sys_get_temp_dir() . "/wm-redis-test-{$uniq}.pid";
Worker::$logFile = sys_get_temp_dir() . "/wm-redis-test-{$uniq}.log";
Worker::$stdoutFile = '/dev/null';
Worker::$daemonize = false;

$emitted = false;

$worker = new Worker();
$worker->count = 1;
$worker->onWorkerStart = function () use ($snippet, $redisUrl, $timeoutSeconds, $resultFd, &$emitted) {
    $redis = new Client($redisUrl);

    $emit = function ($value) use (&$emitted, $resultFd) {
        if ($emitted) {
            return;
        }
        $emitted = true;
        fwrite($resultFd, 'OK ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        fflush($resultFd);
        // Signal the master AND exit — stopAll() alone leaves the master
        // monitoring a child it will immediately try to respawn, leading
        // to a tight emit loop. SIGTERM to the parent (master) followed
        // by exit() in the child cleanly tears down both.
        $ppid = posix_getppid();
        if ($ppid > 1) {
            posix_kill($ppid, SIGTERM);
        }
        exit(0);
    };

    $fail = function ($msg) use (&$emitted, $resultFd) {
        if ($emitted) {
            return;
        }
        $emitted = true;
        fwrite($resultFd, 'FAIL ' . $msg . "\n");
        fflush($resultFd);
        $ppid = posix_getppid();
        if ($ppid > 1) {
            posix_kill($ppid, SIGTERM);
        }
        exit(0);
    };

    Timer::add($timeoutSeconds, function () use ($fail) {
        $fail('timeout');
    }, [], false);

    try {
        eval($snippet);
    } catch (\Throwable $e) {
        $fail('exception: ' . $e->getMessage());
    }
};

Worker::runAll();

// Clean up tempfiles. (Workerman may exit before reaching here, but if it
// returns normally we tidy up.)
@unlink(Worker::$pidFile);
@unlink(Worker::$logFile);
