<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegacyApiIncidenciasTest extends TestCase
{
    use DatabaseTransactions;

    public function test_incidencias_crud_matches_legacy_role_flow(): void
    {
        $zoneId = $this->nextId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Incidencias',
            'qr_code' => 'ZONE-INC-QR',
        ]);

        $employeeId = $this->nextId('users');
        $employeeUsername = 'empleado-inc-' . $employeeId;
        DB::table('users')->insert([
            'id' => $employeeId,
            'username' => $employeeUsername,
            'password_hash' => Hash::make('password'),
            'name' => 'Empleado Incidencias',
            'email' => 'empleado-inc@test.local',
            'role' => 'employee',
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
        ]);

        $coordinatorId = $this->nextId('users');
        $coordinatorUsername = 'coord-inc-' . $coordinatorId;
        DB::table('users')->insert([
            'id' => $coordinatorId,
            'username' => $coordinatorUsername,
            'password_hash' => Hash::make('password'),
            'name' => 'Coordinador Incidencias',
            'email' => 'coord-inc@test.local',
            'role' => 'coordinator',
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
        ]);

        $clientId = $this->nextId('clients');
        DB::table('clients')->insert([
            'id' => $clientId,
            'name' => 'Cliente Incidencias',
            'zone_id' => $zoneId,
            'qr_code' => 'CLIENT-INC-QR',
            'active' => 1,
        ]);

        $employeeToken = $this->postJson('/api/auth/login', [
            'username' => $employeeUsername,
            'password' => 'password',
        ])->json('token');

        $coordinatorToken = $this->postJson('/api/auth/login', [
            'username' => $coordinatorUsername,
            'password' => 'password',
        ])->json('token');

        $adminToken = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
        ])->json('token');

        $incidenciaId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/incidencias', [
                'tipo' => 'cliente',
                'prioridad' => 'alta',
                'titulo' => 'Incidencia cliente',
                'descripcion' => 'Se detecta una incidencia real',
                'clientId' => $clientId,
                'zoneId' => $zoneId,
            ])
            ->assertCreated()
            ->json('incidencia_id');

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->getJson('/api/incidencias')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('incidencias.0.id', $incidenciaId)
            ->assertJsonPath('incidencias.0.client_name', 'Cliente Incidencias');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->putJson('/api/incidencias', [
                'id' => $incidenciaId,
                'respuesta' => 'Revisando ahora mismo',
                'estado' => 'en_revision',
                'coordinadorId' => $coordinatorId,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Incidencia actualizada');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->getJson('/api/incidencias')
            ->assertOk()
            ->assertJsonPath('incidencias.0.estado', 'en_revision')
            ->assertJsonPath('incidencias.0.respuesta', 'Revisando ahora mismo');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->deleteJson('/api/incidencias?id=' . $incidenciaId)
            ->assertOk()
            ->assertJsonPath('message', 'Incidencia eliminada');

        $this->assertDatabaseMissing('incidencias', ['id' => $incidenciaId]);
        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'incidencias',
            'record_id' => $incidenciaId,
            'action' => 'INSERT',
            'changed_by' => $employeeId,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'incidencias',
            'record_id' => $incidenciaId,
            'action' => 'UPDATE',
            'changed_by' => $coordinatorId,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'incidencias',
            'record_id' => $incidenciaId,
            'action' => 'DELETE',
            'changed_by' => 5,
        ]);
    }

    private function nextId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}