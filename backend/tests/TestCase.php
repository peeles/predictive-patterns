<?php

namespace Tests;

use App\Enums\Role;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api.tokens' => [
                ['token' => 'test-token', 'role' => Role::Admin],
            ],
            'api.rate_limits' => [
                Role::Admin->value => 1000,
                Role::Analyst->value => 600,
                Role::Viewer->value => 300,
            ],
        ]);
    }
}
