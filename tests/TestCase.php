<?php

namespace Tests;

use Dotenv\Dotenv;


use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Dotenv::createImmutable(base_path(), '.env.testing')->safeLoad();
    }
}
