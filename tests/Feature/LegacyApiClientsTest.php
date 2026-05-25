<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyApiClientsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_create_update_regenerate_and_delete_client_with_geocoding(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '37.8881751', 'lon' => '-4.7793835'],
            ], 200),
        ]);

        $zoneId = $this->nextLegacyId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Cliente',
            'qr_code' => 'ZONECLIENTETEST',
        ]);

        $createResponse = $this->withAdminToken()->postJson('/api/clients', [
            'name' => 'Cliente Test',
            'address' => 'Calle Mayor 1',
            'postalCode' => '14001',
            'city' => 'Cordoba',
            'province' => 'Cordoba',
            'zoneId' => $zoneId,
            'phone' => '600000000',
            'email' => 'cliente@test.local',
            'notes' => 'Notas iniciales',
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('client.name', 'Cliente Test');

        $clientId = (int) $createResponse->json('client.id');
        $originalQr = (string) $createResponse->json('client.qr_code');

        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'name' => 'Cliente Test',
            'zone_id' => $zoneId,
            'postal_code' => '14001',
        ]);

        $this->withAdminToken()->getJson('/api/clients/'.$clientId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('client.id', $clientId)
            ->assertJsonPath('client.zone_name', 'Zona Cliente');

        $this->withAdminToken()->putJson('/api/clients/'.$clientId, [
            'city' => 'Sevilla',
            'notes' => 'Notas actualizadas',
            'latitude' => 37.38,
            'longitude' => -5.99,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $regenerateResponse = $this->withAdminToken()->postJson('/api/clients/'.$clientId.'/regenerate-qr');
        $regenerateResponse
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotSame($originalQr, (string) $regenerateResponse->json('qr_code'));

        $this->withAdminToken()->deleteJson('/api/clients/'.$clientId)
            ->assertOk()
            ->assertJsonPath('message', 'Cliente eliminado');
    }

    public function test_coordinator_is_scoped_to_own_zone_and_cannot_delete_clients(): void
    {
        $zoneId = $this->nextLegacyId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Coordinador',
            'qr_code' => 'ZONECOORDTEST',
        ]);

        $otherZoneId = $zoneId + 1;
        DB::table('zones')->insert([
            'id' => $otherZoneId,
            'name' => 'Zona Ajena',
            'qr_code' => 'ZONEAJENATEST',
        ]);

        $coordinatorId = $this->nextLegacyId('users');

        $coordinator = User::query()->create([
            'id' => $coordinatorId,
            'username' => 'coord-test',
            'password_hash' => Hash::make('password'),
            'name' => 'Coordinador Test',
            'email' => 'coord@test.local',
            'role' => 'coordinator',
            'zone_id' => $zoneId,
            'active' => true,
            'work_hours' => 8,
            'weekly_hours' => 40,
            'schedule_start' => '09:00:00',
            'schedule_end' => '18:00:00',
        ]);

        Http::fake([
            'https://nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '37.8881751', 'lon' => '-4.7793835'],
            ], 200),
        ]);

        $createResponse = $this->withLogin('coord-test', 'password')->postJson('/api/clients', [
            'name' => 'Cliente Coordinador',
            'address' => 'Calle Coord 1',
            'postalCode' => '14001',
            'city' => 'Cordoba',
            'province' => 'Cordoba',
            'zoneId' => $otherZoneId,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('success', true);

        $clientId = (int) $createResponse->json('client.id');

        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'zone_id' => $zoneId,
        ]);

        $this->withLogin('coord-test', 'password')->postJson('/api/clients/'.$clientId.'/regenerate-qr')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withLogin('coord-test', 'password')->deleteJson('/api/clients/'.$clientId)
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo admin puede eliminar');

        $this->assertNotNull($coordinator->id);
    }

    public function test_geocode_endpoints_and_client_delete_guard_work(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '37.1234567', 'lon' => '-4.1234567'],
            ], 200),
        ]);

        $zoneId = $this->nextLegacyId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Geo',
            'qr_code' => 'ZONEGEOTEST',
        ]);

        $clientId = $this->nextLegacyId('clients');
        DB::table('clients')->insert([
            'id' => $clientId,
            'name' => 'Cliente Geo',
            'address' => 'Calle Geo 1',
            'postal_code' => '14001',
            'city' => 'Cordoba',
            'province' => 'Cordoba',
            'zone_id' => $zoneId,
            'qr_code' => 'CLIGEOTEST',
            'active' => 1,
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->withAdminToken()->postJson('/api/clients/geocode', [
            'address' => 'Calle Geo 1',
            'postalCode' => '14001',
            'city' => 'Cordoba',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('latitude', 37.1234567);

        $this->withAdminToken()->postJson('/api/clients/geocode-all')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('geocoded', 1);

        DB::table('records')->insert([
            'id' => $this->nextLegacyId('records'),
            'employee_id' => 5,
            'type' => 'entrada',
            'client_id' => $clientId,
            'zone_id' => null,
            'timestamp' => now(),
            'confirmed' => 1,
        ]);

        $this->withAdminToken()->deleteJson('/api/clients/'.$clientId)
            ->assertStatus(400)
            ->assertJsonPath('message', 'Cliente tiene fichajes asociados');
    }

    private function withAdminToken(): self
    {
        return $this->withLogin('admin', 'password');
    }

    private function withLogin(string $username, string $password): self
    {
        $token = $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
        ])->json('token');

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}