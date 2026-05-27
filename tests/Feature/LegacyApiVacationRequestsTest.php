<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegacyApiVacationRequestsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_vacation_requests_support_stats_and_coordinator_resolution(): void
    {
        $zoneId = $this->nextId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Vacaciones',
            'qr_code' => 'ZONE-VAC-QR',
        ]);

        $employeeId = $this->nextId('users');
        $employeeUsername = 'empleado-vac-' . $employeeId;
        DB::table('users')->insert([
            'id' => $employeeId,
            'username' => $employeeUsername,
            'password_hash' => Hash::make('password'),
            'name' => 'Empleado Vacaciones',
            'email' => 'empleado-vac@test.local',
            'role' => 'employee',
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
        ]);

        $coordinatorId = $this->nextId('users');
        $coordinatorUsername = 'coord-vac-' . $coordinatorId;
        DB::table('users')->insert([
            'id' => $coordinatorId,
            'username' => $coordinatorUsername,
            'password_hash' => Hash::make('password'),
            'name' => 'Coordinador Vacaciones',
            'email' => 'coord-vac@test.local',
            'role' => 'coordinator',
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
        ]);

        $employeeToken = $this->postJson('/api/auth/login', [
            'username' => $employeeUsername,
            'password' => 'password',
        ])->json('token');

        $coordinatorToken = $this->postJson('/api/auth/login', [
            'username' => $coordinatorUsername,
            'password' => 'password',
        ])->json('token');

        $approvedRequestId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/vacation-requests', [
                'type' => 'vacaciones',
                'start_date' => '2026-07-01',
                'end_date' => '2026-07-05',
                'reason' => 'Viaje familiar',
            ])
            ->assertCreated()
            ->json('request.id');

        $rejectedRequestId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/vacation-requests', [
                'type' => 'asuntos_propios',
                'start_date' => '2026-08-10',
                'end_date' => '2026-08-11',
                'reason' => 'Gestiones personales',
            ])
            ->assertCreated()
            ->json('request.id');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->getJson('/api/vacation-requests')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->putJson('/api/vacation-requests/' . $approvedRequestId . '/approve')
            ->assertOk()
            ->assertJsonPath('message', 'Solicitud aprobada');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->putJson('/api/vacation-requests/' . $rejectedRequestId . '/reject', [
                'reason' => 'No hay cobertura suficiente',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Solicitud rechazada');

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->getJson('/api/vacation-requests/stats?employeeId=' . $employeeId . '&year=2026')
            ->assertOk()
            ->assertJsonPath('stats.vacaciones', 5)
            ->assertJsonPath('stats.asuntos_propios', 0)
            ->assertJsonPath('available_vacaciones', 17)
            ->assertJsonPath('ap_available', 4);

        $this->assertDatabaseHas('vacation_requests', [
            'id' => $approvedRequestId,
            'status' => 'aprobada',
            'approved_by' => $coordinatorId,
        ]);
        $this->assertDatabaseHas('vacation_requests', [
            'id' => $rejectedRequestId,
            'status' => 'rechazada',
            'approved_by' => $coordinatorId,
            'rejection_reason' => 'No hay cobertura suficiente',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employeeId,
            'type' => 'vacation_approved',
            'related_id' => $approvedRequestId,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employeeId,
            'type' => 'vacation_rejected',
            'related_id' => $rejectedRequestId,
        ]);
    }

    private function nextId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}