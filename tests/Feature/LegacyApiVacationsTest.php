<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegacyApiVacationsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_vacations_legacy_controller_supports_request_resolution_and_cancel_flow(): void
    {
        $zoneId = $this->nextId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Vacation Legacy',
            'qr_code' => 'ZONE-VACATIONS-QR',
        ]);

        $employeeId = $this->createUser('employee', $zoneId, 'employee-vacations-legacy');
        $coordinatorId = $this->createUser('coordinator', $zoneId, 'coord-vacations-legacy');

        $employeeToken = $this->login('employee-vacations-legacy');
        $coordinatorToken = $this->login('coord-vacations-legacy');

        $approvedVacationId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/vacations', [
                'startDate' => '2026-09-01',
                'endDate' => '2026-09-03',
                'type' => 'vacation',
                'reason' => 'Descanso familiar',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Solicitud de vacaciones enviada')
            ->json('vacation_id');

        $cancelledVacationId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/vacations', [
                'startDate' => '2026-10-10',
                'endDate' => '2026-10-11',
                'type' => 'permission',
                'reason' => 'Asunto personal',
            ])
            ->assertCreated()
            ->json('vacation_id');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->getJson('/api/vacations')
            ->assertOk()
            ->assertJsonFragment(['id' => $approvedVacationId, 'employee_name' => 'Employee test'])
            ->assertJsonFragment(['id' => $cancelledVacationId, 'employee_name' => 'Employee test']);

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->putJson('/api/vacations/' . $approvedVacationId . '/approve', [
                'notes' => 'Aprobado por coordinación',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Vacaciones aprobadas');

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->deleteJson('/api/vacations/' . $cancelledVacationId)
            ->assertOk()
            ->assertJsonPath('message', 'Solicitud cancelada');

        $this->assertDatabaseHas('vacations', [
            'id' => $approvedVacationId,
            'employee_id' => $employeeId,
            'status' => 'approved',
            'approved_by' => $coordinatorId,
            'notes' => 'Aprobado por coordinación',
        ]);
        $this->assertDatabaseMissing('vacations', ['id' => $cancelledVacationId]);
    }

    private function createUser(string $role, int $zoneId, string $username): int
    {
        $id = $this->nextId('users');
        DB::table('users')->insert([
            'id' => $id,
            'username' => $username,
            'password_hash' => Hash::make('password'),
            'name' => ucfirst($role) . ' test',
            'email' => $username . '@test.local',
            'role' => $role,
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
        ]);

        return $id;
    }

    private function login(string $username): string
    {
        return $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => 'password',
        ])->json('token');
    }

    private function nextId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}