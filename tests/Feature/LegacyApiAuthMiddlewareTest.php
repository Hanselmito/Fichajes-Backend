<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegacyApiAuthMiddlewareTest extends TestCase
{
    public function test_protected_auth_route_requires_bearer_token(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Token no valido o expirado');
    }
}