<?php

namespace Tests\Feature;

use Illuminate\Http\Response;
use Tests\TestCase;

class CorsTest extends TestCase
{
    /** @test */
    public function it_adds_cors_headers_to_preflight_requests(): void
    {
        $response = $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->options('/api/v1/auth/login');

        $response->assertStatus(Response::HTTP_NO_CONTENT);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response->assertHeader('Access-Control-Allow-Methods');
        $response->assertHeader('Access-Control-Allow-Headers');
    }
}
