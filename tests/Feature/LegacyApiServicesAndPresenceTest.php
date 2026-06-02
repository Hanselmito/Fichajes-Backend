<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegacyApiServicesAndPresenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_manage_services_and_service_aware_quadrant_availability(): void
    {
        $zoneId = $this->nextId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Servicios',
            'qr_code' => 'ZONE-SERVICES-QR',
        ]);

        $employeeId = $this->nextId('users');
        DB::table('users')->insert([
            'id' => $employeeId,
            'username' => 'empleado-servicios',
            'password_hash' => Hash::make('password'),
            'name' => 'Empleado Servicios',
            'email' => 'empleado-servicios@test.local',
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
            'name' => 'Cliente Servicios',
            'zone_id' => $zoneId,
            'qr_code' => 'CLIENT-SERVICES-QR',
            'active' => 1,
            'latitude' => 37.88,
            'longitude' => -4.77,
        ]);

        $serviceA = $this->withAdminToken()->postJson('/api/services', [
            'name' => 'Aseo completo',
            'duration_minutes' => 60,
            'color' => '#4CAF50',
        ])->assertCreated();

        $serviceB = $this->withAdminToken()->postJson('/api/services', [
            'name' => 'Control medicacion',
            'duration_minutes' => 30,
            'color' => '#2196F3',
        ])->assertCreated();

        $serviceAId = (int) $serviceA->json('id');
        $serviceBId = (int) $serviceB->json('id');

        DB::table('client_services')->insert([
            ['id' => $this->nextId('client_services'), 'client_id' => $clientId, 'service_id' => $serviceAId],
            ['id' => $this->nextId('client_services') + 1, 'client_id' => $clientId, 'service_id' => $serviceBId],
        ]);

        $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();
        $dayOfWeek = Carbon::today()->dayOfWeekIso;

        $quadrant = $this->withAdminToken()->postJson('/api/quadrants', [
            'name' => 'Semana Servicios',
            'week_start' => $weekStart,
            'zone_id' => $zoneId,
        ])->assertCreated();

        $quadrantId = (int) $quadrant->json('id');

        $this->withAdminToken()->getJson('/api/quadrants?availability=1&zone_id=' . $zoneId . '&day_of_week=' . $dayOfWeek . '&start_time=09:00&end_time=10:00&week_start=' . $weekStart . '&client_id=' . $clientId)
            ->assertOk()
            ->assertJsonPath('required_service_duration_minutes', 90)
            ->assertJsonPath('selected_slot_minutes', 60)
            ->assertJsonPath('service_time_fit', false);

        $this->withAdminToken()->postJson('/api/quadrants/' . $quadrantId . '/assignments', [
            'employee_id' => $employeeId,
            'client_id' => $clientId,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'services' => [$serviceAId, $serviceBId],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La duracion total de los servicios supera el hueco asignado');

        $start = Carbon::now()->subMinutes(30)->format('H:i');
        $end = Carbon::now()->addMinutes(60)->format('H:i');
        $assignment = $this->withAdminToken()->postJson('/api/quadrants/' . $quadrantId . '/assignments', [
            'employee_id' => $employeeId,
            'client_id' => $clientId,
            'day_of_week' => $dayOfWeek,
            'start_time' => $start,
            'end_time' => $end,
            'services' => [$serviceAId, $serviceBId],
            'notes' => 'Servicio completo',
        ])->assertCreated();

        DB::table('records')->insert([
            'id' => $this->nextId('records'),
            'employee_id' => $employeeId,
            'client_id' => $clientId,
            'zone_id' => $zoneId,
            'type' => 'entrada',
            'timestamp' => Carbon::now()->format('Y-m-d H:i:s'),
            'confirmed' => 1,
        ]);

        $assignmentId = (int) $assignment->json('id');

        $this->withAdminToken()->getJson('/api/quadrants/' . $quadrantId)
            ->assertOk()
            ->assertJsonPath('quadrant.assignments.0.id', $assignmentId)
            ->assertJsonPath('quadrant.assignments.0.services_duration_minutes', 90)
            ->assertJsonPath('quadrant.assignments.0.service_coverage_complete', true);

        $this->withAdminToken()->putJson('/api/services/' . $serviceBId, ['active' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Servicio actualizado');

        $this->withAdminToken()->deleteJson('/api/services/' . $serviceBId)
            ->assertOk()
            ->assertJsonPath('message', 'Servicio desactivado');
    }

    public function test_admin_can_port_presence_endpoints_over_weekly_schedule_data(): void
    {
        $zoneId = $this->nextId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Presencia',
            'qr_code' => 'ZONE-PRESENCE-QR',
        ]);

        $employeeId = $this->nextId('users');
        DB::table('users')->insert([
            'id' => $employeeId,
            'username' => 'empleado-presencia',
            'password_hash' => Hash::make('password'),
            'name' => 'Empleado Presencia',
            'email' => 'empleado-presencia@test.local',
            'role' => 'employee',
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
            'schedule_start' => '09:00:00',
            'schedule_end' => '18:00:00',
            'contract_start' => Carbon::today()->startOfMonth()->toDateString(),
        ]);

        $effectiveDate = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->withAdminToken()->postJson('/api/employee-schedules', [
            'employeeId' => $employeeId,
            'effective_date' => $effectiveDate,
            'weekly_hours' => 40,
            'schedules' => [
                ['day_of_week' => 'lunes', 'is_workday' => 1, 'segments' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                ['day_of_week' => 'martes', 'is_workday' => 1, 'segments' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                ['day_of_week' => 'miercoles', 'is_workday' => 1, 'segments' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                ['day_of_week' => 'jueves', 'is_workday' => 1, 'segments' => [['start_time' => '09:00', 'end_time' => '17:00']]],
                ['day_of_week' => 'viernes', 'is_workday' => 1, 'segments' => [['start_time' => '09:00', 'end_time' => '17:00']]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Horario configurado');

        $today = Carbon::today();
        DB::table('records')->insert([
            ['id' => $this->nextId('records'), 'employee_id' => $employeeId, 'zone_id' => $zoneId, 'type' => 'entrada', 'timestamp' => $today->copy()->setTime(9, 0)->format('Y-m-d H:i:s'), 'confirmed' => 1],
            ['id' => $this->nextId('records') + 1, 'employee_id' => $employeeId, 'zone_id' => $zoneId, 'type' => 'salida', 'timestamp' => $today->copy()->setTime(13, 0)->format('Y-m-d H:i:s'), 'confirmed' => 1],
        ]);

        $this->withAdminToken()->getJson('/api/employee-schedules?employeeId=' . $employeeId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('schedules.1.day_of_week', 'lunes');

        $this->withAdminToken()->getJson('/api/schedule-history?employeeId=' . $employeeId . '&date=' . $effectiveDate)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('current.employee_id', $employeeId);

        $this->withAdminToken()->getJson('/api/work-hours?today=1&employeeId=' . $employeeId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('hours_worked', 4);

        $this->withAdminToken()->getJson('/api/work-hours?balance=1&employeeId=' . $employeeId . '&month=' . $today->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withAdminToken()->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withAdminToken()->getJson('/api/reports/stats')
            ->assertOk()
            ->assertJsonPath('success', true);

        $reportsJson = $this->withAdminToken()->getJson('/api/reports/json')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertGreaterThanOrEqual(2, (int) $reportsJson->json('report.total_records'));
    }

    private function withAdminToken(): self
    {
        return $this->withLegacyBearerToken('admin');
    }

    private function nextId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}