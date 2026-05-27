<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyApiAdminEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_remaining_admin_endpoints_match_legacy_contracts(): void
    {
        Http::fake([
            'https://api.qrserver.com/*' => Http::response('png-data', 200, ['Content-Type' => 'image/png']),
            'https://chart.googleapis.com/*' => Http::response('fallback-png', 200, ['Content-Type' => 'image/png']),
            'https://date.nager.at/*' => Http::response([
                [
                    'date' => '2026-04-02',
                    'localName' => 'Jueves Santo',
                    'name' => 'Maundy Thursday',
                    'counties' => ['ES-MD'],
                ],
            ], 200),
        ]);

        $zoneId = $this->nextId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Admin Endpoints',
            'qr_code' => 'ZONE-ENDPOINTS-QR',
            'region_code' => 'ES-MD',
        ]);

        $adminId = $this->createUser('admin', $zoneId, 'admin-admin-endpoints');
        $coordinatorId = $this->createUser('coordinator', $zoneId, 'coord-admin-endpoints');
        $employeeId = $this->createUser('employee', $zoneId, 'employee-admin-endpoints');

        $adminToken = $this->login('admin-admin-endpoints');
        $coordinatorToken = $this->login('coord-admin-endpoints');
        $employeeToken = $this->login('employee-admin-endpoints');

        $nationalHolidayId = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/zone-holidays', [
                'name' => 'Fiesta Nacional Test',
                'date' => '2020-10-12',
                'type' => 'national',
                'recurring' => true,
            ])
            ->assertCreated()
            ->json('id');

        $zoneHolidayId = $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->postJson('/api/zone-holidays', [
                'name' => 'Festivo Local Test',
                'date' => '2026-05-15',
                'type' => 'local',
                'recurring' => false,
                'zoneId' => 9999,
            ])
            ->assertCreated()
            ->json('id');

        $this->assertDatabaseHas('zone_holidays', [
            'id' => $zoneHolidayId,
            'zone_id' => $zoneId,
            'created_by' => $coordinatorId,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->getJson('/api/zone-holidays?zoneId=' . $zoneId . '&year=2026')
            ->assertOk()
            ->assertJsonFragment(['id' => $zoneHolidayId, 'name' => 'Festivo Local Test', 'date' => '2026-05-15'])
            ->assertJsonFragment(['id' => $nationalHolidayId, 'name' => 'Fiesta Nacional Test', 'date' => '2026-10-12']);

        $calendarId = $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->postJson('/api/calendars', [
                'name' => 'Calendario Personal Test',
                'description' => 'Calendario de pruebas',
                'region_code' => 'ES-MD',
            ])
            ->assertCreated()
            ->assertJsonPath('imported', 1)
            ->json('id');

        $this->assertDatabaseHas('calendars', [
            'id' => $calendarId,
            'created_by' => $coordinatorId,
        ]);

        $calendarHolidayId = $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->postJson('/api/calendars/' . $calendarId . '/holidays', [
                'name' => 'Festivo Propio',
                'date' => '2026-11-09',
                'type' => 'local',
                'recurring' => false,
            ])
            ->assertCreated()
            ->json('id');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->getJson('/api/calendars/' . $calendarId . '/holidays?year=2026')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Festivo Propio', 'date' => '2026-11-09', 'source' => 'calendar'])
            ->assertJsonFragment(['name' => 'Jueves Santo', 'date' => '2026-04-02', 'source' => 'calendar'])
            ->assertJsonFragment(['name' => 'Fiesta Nacional Test', 'date' => '2026-10-12', 'source' => 'national']);

        $scheduleBaseId = $this->nextId('employee_schedules');
        DB::table('employee_schedules')->insert([
            [
                'id' => $scheduleBaseId,
                'employee_id' => $employeeId,
                'day_of_week' => 1,
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
                'is_working_day' => 1,
                'entry_tolerance_minutes' => 15,
                'exit_tolerance_minutes' => 15,
            ],
            [
                'id' => $scheduleBaseId + 1,
                'employee_id' => $employeeId,
                'day_of_week' => 2,
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
                'is_working_day' => 1,
                'entry_tolerance_minutes' => 15,
                'exit_tolerance_minutes' => 15,
            ],
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/tolerance/zone?zoneId=' . $zoneId)
            ->assertOk()
            ->assertJsonPath('settings.default_entry_tolerance', 15);

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/tolerance/zone', [
                'zoneId' => $zoneId,
                'defaultEntryTolerance' => 7,
                'defaultExitTolerance' => 9,
                'notifyCoordinator' => false,
                'notifyAfterMinutes' => 12,
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->putJson('/api/tolerance/employee/' . $employeeId . '/all', [
                'entryTolerance' => 4,
                'exitTolerance' => 6,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Tolerancia actualizada para 2 días');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->getJson('/api/tolerance/employee/' . $employeeId)
            ->assertOk()
            ->assertJsonPath('schedules.0.entry_tolerance_minutes', 4)
            ->assertJsonPath('schedules.1.exit_tolerance_minutes', 6);

        $annotationId = $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->postJson('/api/bolsa-anotaciones', [
                'employee_id' => $employeeId,
                'date' => '2026-06-03',
                'text' => 'Ajuste por reunión extraordinaria',
                'affects_hours' => true,
                'hours_adjustment' => 1.5,
                'color' => '#123456',
            ])
            ->assertCreated()
            ->json('id');

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->getJson('/api/bolsa-anotaciones?month=2026-06')
            ->assertOk()
            ->assertJsonPath('anotaciones.0.text', 'Ajuste por reunión extraordinaria');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->putJson('/api/bolsa-anotaciones/' . $annotationId, [
                'text' => 'Ajuste revisado',
                'affects_hours' => false,
                'color' => '#654321',
            ])
            ->assertOk();

        $this->get('/api/qr-generator?code=TEST-QR&size=120')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->deleteJson('/api/calendars/' . $calendarId . '/holidays/' . $calendarHolidayId)
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->deleteJson('/api/zone-holidays?id=' . $zoneHolidayId)
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->deleteJson('/api/bolsa-anotaciones/' . $annotationId)
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->deleteJson('/api/calendars/' . $calendarId)
            ->assertOk();

        $this->assertDatabaseHas('zone_tolerance_settings', [
            'zone_id' => $zoneId,
            'default_entry_tolerance' => 7,
            'default_exit_tolerance' => 9,
            'notify_coordinator' => 0,
            'notify_after_minutes' => 12,
        ]);
        $this->assertDatabaseHas('zone_holidays', [
            'id' => $nationalHolidayId,
            'zone_id' => null,
            'created_by' => $adminId,
        ]);
        $this->assertDatabaseMissing('zone_holidays', ['id' => $zoneHolidayId]);
        $this->assertDatabaseMissing('calendar_holidays', ['id' => $calendarHolidayId]);
        $this->assertDatabaseMissing('bolsa_anotaciones', ['id' => $annotationId]);
        $this->assertDatabaseMissing('calendars', ['id' => $calendarId]);
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