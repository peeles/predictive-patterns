<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api.tokens' => ['test-token'],
            'api.rate_limit' => 1000,
        ]);
    }
}
