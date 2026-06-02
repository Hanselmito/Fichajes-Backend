<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    /** @var array<string, string> */
    protected array $legacyBearerTokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->legacyBearerTokens = [];
    }

    protected function withLegacyBearerToken(string $username, string $password = 'password'): static
    {
        if (! isset($this->legacyBearerTokens[$username])) {
            $token = $this->postJson('/api/auth/login', [
                'username' => $username,
                'password' => $password,
            ])->json('token');

            $this->assertIsString($token);
            $this->assertNotSame('', $token);

            $this->legacyBearerTokens[$username] = $token;
        }

        return $this->withHeader('Authorization', 'Bearer ' . $this->legacyBearerTokens[$username]);
    }
}
