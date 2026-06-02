<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LegacyApiParityGuardsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_api_routes_do_not_keep_unresolved_closure_todo_endpoints(): void
    {
        $closureUris = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/'))
            ->filter(static fn ($route): bool => ($route->getActionName() === 'Closure'))
            ->map(static fn ($route): string => $route->uri())
            ->values()
            ->all();

        $this->assertSame(['api/health'], $closureUris);
    }

    public function test_missing_checkins_command_is_registered_for_operational_parity(): void
    {
        $this->artisan('legacy:check-missing-checkins', ['--help' => true])
            ->assertExitCode(0);
    }
}