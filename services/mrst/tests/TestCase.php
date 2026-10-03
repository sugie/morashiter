<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if (
            getenv('APP_ENV') !== 'testing'
            || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_HOST') !== 'db'
            || getenv('DB_PORT') !== '5432'
            || getenv('DB_DATABASE') !== 'mrst_test'
            || getenv('DB_USERNAME') !== 'mrst_app'
            || getenv('DB_URL') !== ''
        ) {
            throw new RuntimeException('Tests must run against the dedicated mrst_test PostgreSQL database.');
        }

        parent::setUp();
    }
}
