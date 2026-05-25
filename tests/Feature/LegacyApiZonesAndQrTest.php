<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyApiZonesAndQrTest extends TestCase
{
    use DatabaseTransactions;

    public function test_zone_crud_and_regenerate_qr_work(): void
    {
        $createResponse = $this->withAdminToken()->postJson('/api/zones', [
            'name' => 'Zona Test',
            'address' => 'Calle Test 1',
            'postalCode' => '14001',
            'city' => 'Cordoba',
            'province' => 'Cordoba',
            'regionCode' => 'AN',
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('zone.name', 'Zona Test');

        $zoneId = (int) $createResponse->json('zone.id');
        $originalQr = (string) $createResponse->json('zone.qr_code');

        $this->withAdminToken()->getJson('/api/zones')
            ->assertOk()
            ->assertJsonFragment(['id' => $zoneId, 'name' => 'Zona Test']);

        $this->withAdminToken()->putJson('/api/zones/'.$zoneId, [
            'city' => 'Sevilla',
        ])->assertOk()->assertJsonPath('message', 'Zona actualizada');

        $regenerateResponse = $this->withAdminToken()->postJson('/api/zones/'.$zoneId.'/regenerate-qr');

        $regenerateResponse
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotSame($originalQr, (string) $regenerateResponse->json('qr_code'));

        $this->withAdminToken()->deleteJson('/api/zones/'.$zoneId)
            ->assertOk()
            ->assertJsonPath('message', 'Zona eliminada correctamente');
    }

    public function test_qr_lookup_returns_zone_and_client_payloads(): void
    {
        $zoneId = $this->nextLegacyId('zones');

        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona QR',
            'qr_code' => 'ZONEQRTEST',
        ]);

        $clientId = $this->nextLegacyId('clients');

        DB::table('clients')->insert([
            'id' => $clientId,
            'name' => 'Cliente QR',
            'zone_id' => $zoneId,
            'qr_code' => 'CLIQRTEST',
            'active' => 1,
        ]);

        $this->withAdminToken()->getJson('/api/clients/qr/ZONEQRTEST')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('type', 'zone')
            ->assertJsonPath('zone.id', $zoneId);

        $this->withAdminToken()->getJson('/api/clients/qr/CLIQRTEST')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('type', 'client')
            ->assertJsonPath('client.id', $clientId)
            ->assertJsonPath('client.zone_name', 'Zona QR');

        $this->withAdminToken()->getJson('/api/clients?id='.$clientId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('client.id', $clientId)
            ->assertJsonPath('client.name', 'Cliente QR');
    }

    private function withAdminToken(): self
    {
        $token = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
        ])->json('token');

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}