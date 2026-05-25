<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegacyApiQuadrantsAndSchedulesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_manage_quadrant_assignments_and_exceptions(): void
    {
        $zoneId = $this->nextId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Cuadrante',
            'qr_code' => 'ZONEQUADRANTTEST',
        ]);

        $employeeId = $this->nextId('users');
        DB::table('users')->insert([
            'id' => $employeeId,
            'username' => 'empleado-cuadrante',
            'password_hash' => Hash::make('password'),
            'name' => 'Empleado Cuadrante',
            'email' => 'empleado-cuadrante@test.local',
            'role' => 'employee',
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
            'schedule_start' => '09:00:00',
            'schedule_end' => '18:00:00',
        ]);

        $clientId = $this->nextId('clients');
        DB::table('clients')->insert([
            'id' => $clientId,
            'name' => 'Cliente Cuadrante',
            'zone_id' => $zoneId,
            'qr_code' => 'CLICUADRANTTEST',
            'active' => 1,
            'latitude' => 37.88,
            'longitude' => -4.77,
        ]);

        $createQuadrant = $this->withAdminToken()->postJson('/api/quadrants', [
            'name' => 'Semana Cordoba',
            'week_start' => '2026-05-25',
            'zone_id' => $zoneId,
        ]);

        $createQuadrant
            ->assertCreated()
            ->assertJsonPath('success', true);

        $quadrantId = (int) $createQuadrant->json('id');

        $createAssignment = $this->withAdminToken()->postJson('/api/quadrants/'.$quadrantId.'/assignments', [
            'employee_id' => $employeeId,
            'client_id' => $clientId,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'services' => [1, 2],
            'notes' => 'Visita inicial',
        ]);

        $createAssignment
            ->assertCreated()
            ->assertJsonPath('success', true);

        $assignmentId = (int) $createAssignment->json('id');

        $this->withAdminToken()->getJson('/api/quadrants/'.$quadrantId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('quadrant.id', $quadrantId)
            ->assertJsonPath('quadrant.assignments.0.id', $assignmentId);

        $this->withAdminToken()->putJson('/api/quadrants/'.$quadrantId.'/assignments/'.$assignmentId, [
            'end_time' => '11:30',
            'notes' => 'Visita ampliada',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Asignación actualizada');

        $createException = $this->withAdminToken()->postJson('/api/quadrants/'.$quadrantId.'/assignments/'.$assignmentId.'/exceptions', [
            'exception_date' => '2026-05-25',
            'type' => 'modified',
            'new_start_time' => '10:00',
            'new_end_time' => '12:00',
            'reason' => 'Cambio puntual',
        ]);

        $createException
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->withAdminToken()->getJson('/api/quadrants?employee=1&employeeId='.$employeeId.'&week_start=2026-05-25')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('assignments.0.start_time', '10:00:00');

        $this->withAdminToken()->deleteJson('/api/quadrants/'.$quadrantId.'/assignments/'.$assignmentId)
            ->assertOk()
            ->assertJsonPath('message', 'Asignación eliminada');

        $this->withAdminToken()->deleteJson('/api/quadrants/'.$quadrantId)
            ->assertOk()
            ->assertJsonPath('message', 'Cuadrante eliminado');
    }

    public function test_admin_can_configure_employee_schedules_and_read_today_status(): void
    {
        $zoneId = $this->nextId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Horarios',
            'qr_code' => 'ZONESCHEDULETEST',
        ]);

        $employeeId = $this->nextId('users');
        DB::table('users')->insert([
            'id' => $employeeId,
            'username' => 'empleado-horario',
            'password_hash' => Hash::make('password'),
            'name' => 'Empleado Horario',
            'email' => 'empleado-horario@test.local',
            'role' => 'employee',
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
            'schedule_start' => '09:00:00',
            'schedule_end' => '18:00:00',
        ]);

        $this->withAdminToken()->postJson('/api/schedules', [
            'employeeId' => $employeeId,
            'schedules' => [
                [
                    'dayOfWeek' => 1,
                    'startTime' => '09:00',
                    'endTime' => '17:00',
                    'isWorkingDay' => true,
                    'toleranceMinutes' => 10,
                ],
                [
                    'dayOfWeek' => 2,
                    'startTime' => '09:00',
                    'endTime' => '17:00',
                    'isWorkingDay' => true,
                    'toleranceMinutes' => 10,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Horario configurado');

        $this->withAdminToken()->getJson('/api/schedules/'.$employeeId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('schedules.0.employee_id', $employeeId);

        $this->withAdminToken()->getJson('/api/schedules/status/today')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function withAdminToken(): self
    {
        $token = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
        ])->json('token');

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function nextId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}