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

    public function test_admin_can_login_and_fetch_me(): void
    {
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.username', 'admin');

        $token = $loginResponse->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.username', 'admin');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/capabilities')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('capabilities.user.username', 'admin')
            ->assertJsonPath('capabilities.navigation.dashboard', true)
            ->assertJsonPath('capabilities.navigation.users', true)
            ->assertJsonPath('capabilities.permissions.can_view_reports.allowed', true)
            ->assertJsonPath('capabilities.resource_access.users.manage', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
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