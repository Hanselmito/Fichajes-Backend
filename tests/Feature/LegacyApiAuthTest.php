<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LegacyApiAuthTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.legacy_api.login_max_attempts', 5);
        config()->set('auth.legacy_api.login_decay_seconds', 60);
        Cache::flush();
    }

    public function test_admin_can_login_refresh_and_logout(): void
    {
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('token', $loginResponse->json('access_token'))
            ->assertJsonPath('user.username', 'admin');

        $accessToken = $loginResponse->json('access_token');
        $refreshToken = $loginResponse->json('refresh_token');

        $this->assertIsString($accessToken);
        $this->assertIsString($refreshToken);
        $this->assertNotSame('', $accessToken);
        $this->assertNotSame('', $refreshToken);

        $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.username', 'admin');

        $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->getJson('/api/auth/capabilities')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('capabilities.user.username', 'admin')
            ->assertJsonPath('capabilities.navigation.dashboard', true)
            ->assertJsonPath('capabilities.navigation.users', true)
            ->assertJsonPath('capabilities.permissions.can_view_reports.allowed', true)
            ->assertJsonPath('capabilities.resource_access.users.manage', true);

        $refreshResponse = $this->postJson('/api/auth/refresh', [
            'refreshToken' => $refreshToken,
        ]);

        $refreshResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('token', $refreshResponse->json('access_token'))
            ->assertJsonPath('user.username', 'admin');

        $rotatedAccessToken = $refreshResponse->json('access_token');
        $rotatedRefreshToken = $refreshResponse->json('refresh_token');

        $this->assertNotSame($accessToken, $rotatedAccessToken);
        $this->assertNotSame($refreshToken, $rotatedRefreshToken);

        $this->postJson('/api/auth/refresh', [
            'refreshToken' => $refreshToken,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->withHeader('Authorization', 'Bearer '.$rotatedAccessToken)
            ->postJson('/api/auth/logout', [
                'refreshToken' => $rotatedRefreshToken,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeader('Authorization', 'Bearer '.$rotatedAccessToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->postJson('/api/auth/refresh', [
            'refreshToken' => $rotatedRefreshToken,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        config()->set('auth.legacy_api.login_max_attempts', 2);

        $payload = [
            'username' => 'rate-limit-user',
            'password' => 'wrong-password',
        ];

        $this->postJson('/api/auth/login', $payload)
            ->assertUnauthorized();

        $this->postJson('/api/auth/login', $payload)
            ->assertUnauthorized();

        $this->postJson('/api/auth/login', $payload)
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }
}