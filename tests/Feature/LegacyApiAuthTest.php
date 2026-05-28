<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LegacyApiAuthTest extends TestCase
{
    use DatabaseTransactions;

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
    }
}