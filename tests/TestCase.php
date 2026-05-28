<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Redis URL used by integration tests. Override with the REDIS_URL env var.
     */
    protected function redisUrl(): string
    {
        return getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379';
    }

    /**
     * Skip the current test if a TCP connection to the Redis server cannot be opened.
     *
     * Integration tests call this in their setUp() so the suite still passes on machines
     * without a running Redis (CI, contributor laptops).
     */
    protected function skipWithoutRedis(): void
    {
        $url = $this->redisUrl();
        $parts = parse_url($url);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int)($parts['port'] ?? 6379);
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if (!$fp) {
            $this->markTestSkipped("Redis not reachable at {$host}:{$port} ({$errstr})");
        }
        fclose($fp);
    }
}
