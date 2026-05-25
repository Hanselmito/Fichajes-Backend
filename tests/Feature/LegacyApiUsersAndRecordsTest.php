<?php

namespace Tests\Feature;

use App\Models\Record;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LegacyApiUsersAndRecordsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_users_index_returns_admin_user(): void
    {
        $response = $this->withAdminToken()->getJson('/api/users');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['username' => 'admin']);
    }

    public function test_records_can_be_created_and_confirmed(): void
    {
        $createResponse = $this->withAdminToken()->postJson('/api/records', [
            'type' => 'entrada',
            'clientId' => null,
            'latitude' => 0,
            'longitude' => 0,
            'device' => 'Manual',
            'isTeletrabajo' => true,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('success', true);

        $recordId = (int) $createResponse->json('record_id');

        $this->assertDatabaseHas('records', [
            'id' => $recordId,
            'employee_id' => 5,
            'type' => 'entrada',
            'confirmed' => 0,
        ]);

        $this->withAdminToken()
            ->putJson('/api/records/'.$recordId.'/confirm')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Fichaje confirmado');

        $this->assertTrue((bool) Record::query()->findOrFail($recordId)->confirmed);
    }

    private function withAdminToken(): self
    {
        $token = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
        ])->json('token');

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}