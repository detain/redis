<?php

namespace Tests;

/**
 * Base class for Feature/ tests. Skips the suite when no Redis is reachable
 * so the project builds cleanly on machines without a server.
 *
 * The runInWorker() helper lives as a free function in tests/Pest.php
 * (not a method here) so Pest-bound closures can call it without a
 * $this->... reference that PHPStan can't resolve on older PHP versions.
 */
abstract class RedisTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipWithoutRedis();
    }
}
