<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\SanctumTokenManager;
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
            'user' => ['id', 'name', 'email', 'role'],
            'expiresIn',
        ]);
        $response->assertJsonMissingPath('refreshToken');
        $response->assertCookie(SanctumTokenManager::REFRESH_COOKIE_NAME);

        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_refresh_rotates_tokens(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
            'role' => Role::Analyst,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $loginResponse->assertCookie(SanctumTokenManager::REFRESH_COOKIE_NAME);

        $login = $loginResponse->json();
        $refreshToken = $loginResponse->getCookie(SanctumTokenManager::REFRESH_COOKIE_NAME)?->getValue();

        $this->assertIsString($refreshToken);
        $this->assertNotSame('', $refreshToken);

        $refreshResponse = $this->withCookie(SanctumTokenManager::REFRESH_COOKIE_NAME, $refreshToken)
            ->postJson('/api/auth/refresh');

        $refreshResponse->assertOk();
        $refreshResponse->assertJsonStructure([
            'accessToken',
            'user' => ['id', 'name', 'email', 'role'],
            'expiresIn',
        ]);
        $refreshResponse->assertJsonMissingPath('refreshToken');
        $refreshResponse->assertCookie(SanctumTokenManager::REFRESH_COOKIE_NAME);

        $refreshed = $refreshResponse->json();
        $newRefreshToken = $refreshResponse->getCookie(SanctumTokenManager::REFRESH_COOKIE_NAME)?->getValue();

        $this->assertNotSame($login['accessToken'], $refreshed['accessToken']);
        $this->assertIsString($newRefreshToken);
        $this->assertNotSame($refreshToken, $newRefreshToken);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_login_rate_limit_returns_too_many_requests(): void
    {
        config(['api.auth_rate_limits.login' => 2]);

        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
            'role' => Role::Viewer,
        ]);

        for ($i = 0; $i < 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->postJson('/api/auth/login', [
                    'email' => $user->email,
                    'password' => 'secret-password',
                ])
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'secret-password',
            ])
            ->assertTooManyRequests();
    }

    public function test_refresh_rate_limit_returns_too_many_requests(): void
    {
        config(['api.auth_rate_limits.refresh' => 2]);

        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
            'role' => Role::Viewer,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->json();

        for ($i = 0; $i < 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
                ->postJson('/api/auth/refresh', [
                    'refreshToken' => $login['refreshToken'],
                ])
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->postJson('/api/auth/refresh', [
                'refreshToken' => $login['refreshToken'],
            ])
            ->assertTooManyRequests();
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

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $tokens = $loginResponse->json();
        $refreshCookie = $loginResponse->getCookie(SanctumTokenManager::REFRESH_COOKIE_NAME);
        $this->assertNotNull($refreshCookie);

        $logoutResponse = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->withCookie($refreshCookie->getName(), $refreshCookie->getValue())
            ->postJson('/api/auth/logout');

        $logoutResponse->assertOk();

        $clearedCookie = $logoutResponse->getCookie(SanctumTokenManager::REFRESH_COOKIE_NAME);
        $this->assertNotNull($clearedCookie);
        $this->assertLessThan(time(), $clearedCookie->getExpiresTime());

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
