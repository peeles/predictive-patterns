<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_tokens_and_user_profile(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
            'role' => Role::Admin,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'accessToken',
            'refreshToken',
            'user' => ['id', 'name', 'email', 'role'],
            'expiresIn',
        ]);

        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_refresh_rotates_tokens(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
            'role' => Role::Analyst,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->json();

        $refreshResponse = $this->postJson('/api/auth/refresh', [
            'refreshToken' => $login['refreshToken'],
        ]);

        $refreshResponse->assertOk();
        $refreshResponse->assertJsonStructure([
            'accessToken',
            'refreshToken',
            'user' => ['id', 'name', 'email', 'role'],
            'expiresIn',
        ]);

        $refreshed = $refreshResponse->json();

        $this->assertNotSame($login['accessToken'], $refreshed['accessToken']);
        $this->assertNotSame($login['refreshToken'], $refreshed['refreshToken']);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
            'role' => Role::Viewer,
        ]);

        $tokens = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->json();

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/auth/me');

        $response->assertOk();
        $response->assertJson([
            'id' => $user->getKey(),
            'email' => $user->email,
            'role' => Role::Viewer->value,
        ]);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
            'role' => Role::Admin,
        ]);

        $tokens = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->json();

        $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => $this->hashPersonalAccessToken($tokens['accessToken']),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_query_string_api_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
            'role' => Role::Viewer,
        ]);

        $tokens = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->json();

        $this->getJson('/api/auth/me?api_token='.$tokens['accessToken'])
            ->assertUnauthorized();
    }

}
