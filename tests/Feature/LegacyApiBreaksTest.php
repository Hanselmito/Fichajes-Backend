<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegacyApiBreaksTest extends TestCase
{
    use DatabaseTransactions;

    public function test_breaks_are_tracked_and_discounted_from_real_worked_hours_metrics(): void
    {
        $zoneId = $this->nextId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Descansos',
            'qr_code' => 'ZONE-BREAKS-QR',
        ]);

        $employeeId = $this->nextId('users');
        $employeeUsername = 'empleado-breaks-' . $employeeId;
        DB::table('users')->insert([
            'id' => $employeeId,
            'username' => $employeeUsername,
            'password_hash' => Hash::make('password'),
            'name' => 'Empleado Descansos',
            'email' => 'empleado-breaks@test.local',
            'role' => 'employee',
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
            'schedule_start' => '09:00:00',
            'schedule_end' => '18:00:00',
            'contract_start' => Carbon::today()->startOfMonth()->toDateString(),
        ]);

        DB::table('records')->insert([
            'id' => $this->nextId('records'),
            'employee_id' => $employeeId,
            'zone_id' => $zoneId,
            'type' => 'entrada',
            'timestamp' => Carbon::today()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'confirmed' => 1,
        ]);

        $employeeToken = $this->postJson('/api/auth/login', [
            'username' => $employeeUsername,
            'password' => 'password',
        ])->json('token');

        Carbon::setTestNow(Carbon::today()->setTime(10, 0));
        $breakId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/breaks', ['break_type' => 'cafe'])
            ->assertCreated()
            ->json('break_id');

        Carbon::setTestNow(Carbon::today()->setTime(10, 20));
        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->putJson('/api/breaks')
            ->assertOk()
            ->assertJsonPath('duration_minutes', 20)
            ->assertJsonPath('break_type', 'cafe');

        Carbon::setTestNow();

        DB::table('records')->insert([
            'id' => $this->nextId('records'),
            'employee_id' => $employeeId,
            'zone_id' => $zoneId,
            'type' => 'salida',
            'timestamp' => Carbon::today()->setTime(13, 0)->format('Y-m-d H:i:s'),
            'confirmed' => 1,
        ]);

        $adminToken = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/breaks?employeeId=' . $employeeId . '&date=' . Carbon::today()->toDateString())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total_minutes', 20)
            ->assertJsonPath('total_hours', 0.33)
            ->assertJsonPath('in_progress', null)
            ->assertJsonPath('breaks.0.id', (int) $breakId)
            ->assertJsonPath('breaks.0.duration_minutes', 20);

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/work-hours?today=1&employeeId=' . $employeeId)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('hours_worked', 3.67);

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/work-hours?balance=1&employeeId=' . $employeeId . '&month=' . Carbon::today()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total_hours_worked', 3.67);

        $dashboard = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true);

        $employee = collect($dashboard->json('employees'))->firstWhere('id', $employeeId);
        $this->assertNotNull($employee);
        $this->assertSame(3.67, (float) $employee['hours_worked_week']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function nextId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}