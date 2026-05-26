<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegacyApiModificationsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_modification_requests_and_confirmations_close_the_approval_loop(): void
    {
        $zoneId = $this->nextId('zones');
        DB::table('zones')->insert([
            'id' => $zoneId,
            'name' => 'Zona Modificaciones',
            'qr_code' => 'ZONE-MODS-QR',
        ]);

        $employeeId = $this->nextId('users');
        $employeeUsername = 'empleado-mods-' . $employeeId;
        DB::table('users')->insert([
            'id' => $employeeId,
            'username' => $employeeUsername,
            'password_hash' => Hash::make('password'),
            'name' => 'Empleado Mods',
            'email' => 'empleado-mods@test.local',
            'role' => 'employee',
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
        ]);

        $coordinatorId = $this->nextId('users');
        $coordinatorUsername = 'coord-mods-' . $coordinatorId;
        DB::table('users')->insert([
            'id' => $coordinatorId,
            'username' => $coordinatorUsername,
            'password_hash' => Hash::make('password'),
            'name' => 'Coordinador Mods',
            'email' => 'coord-mods@test.local',
            'role' => 'coordinator',
            'zone_id' => $zoneId,
            'active' => 1,
            'work_hours' => 8,
            'weekly_hours' => 40,
        ]);

        $recordBaseId = $this->nextId('records');
        DB::table('records')->insert([
            [
                'id' => $recordBaseId,
                'employee_id' => $employeeId,
                'zone_id' => $zoneId,
                'type' => 'entrada',
                'timestamp' => Carbon::today()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'confirmed' => 1,
            ],
            [
                'id' => $recordBaseId + 1,
                'employee_id' => $employeeId,
                'zone_id' => $zoneId,
                'type' => 'salida',
                'timestamp' => Carbon::today()->setTime(14, 0)->format('Y-m-d H:i:s'),
                'confirmed' => 1,
            ],
            [
                'id' => $recordBaseId + 2,
                'employee_id' => $employeeId,
                'zone_id' => $zoneId,
                'type' => 'entrada',
                'timestamp' => Carbon::today()->subDay()->setTime(8, 0)->format('Y-m-d H:i:s'),
                'confirmed' => 1,
            ],
            [
                'id' => $recordBaseId + 3,
                'employee_id' => $employeeId,
                'zone_id' => $zoneId,
                'type' => 'salida',
                'timestamp' => Carbon::today()->subDay()->setTime(15, 0)->format('Y-m-d H:i:s'),
                'confirmed' => 1,
            ],
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
            ->postJson('/api/modifications/requests', [
                'recordId' => $recordBaseId,
                'newDate' => Carbon::today()->toDateString(),
                'newTime' => '09:30',
                'reason' => 'Olvide fichar a tiempo',
            ])
            ->assertCreated()
            ->json('request_id');

        $rejectedRequestId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/modifications/requests', [
                'recordId' => $recordBaseId + 1,
                'newDate' => Carbon::today()->toDateString(),
                'newTime' => '13:30',
                'reason' => 'Quiero ajustar la salida',
            ])
            ->assertCreated()
            ->json('request_id');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->getJson('/api/modifications/requests?status=pending')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->putJson('/api/modifications/requests/' . $approvedRequestId . '/approve')
            ->assertOk()
            ->assertJsonPath('message', 'Solicitud aprobada y fichaje actualizado');

        $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->putJson('/api/modifications/requests/' . $rejectedRequestId . '/reject')
            ->assertOk()
            ->assertJsonPath('message', 'Solicitud rechazada');

        $this->assertDatabaseHas('modification_requests', [
            'id' => $approvedRequestId,
            'status' => 'approved',
            'approved_by' => $coordinatorId,
        ]);
        $this->assertDatabaseHas('modification_requests', [
            'id' => $rejectedRequestId,
            'status' => 'rejected',
            'approved_by' => $coordinatorId,
        ]);
        $this->assertSame(
            Carbon::today()->setTime(9, 30)->format('Y-m-d H:i:s'),
            DB::table('records')->where('id', $recordBaseId)->value('timestamp')
        );
        $this->assertSame(
            Carbon::today()->setTime(14, 0)->format('Y-m-d H:i:s'),
            DB::table('records')->where('id', $recordBaseId + 1)->value('timestamp')
        );

        $confirmedProposalId = $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->postJson('/api/modifications/confirmations', [
                'recordId' => $recordBaseId + 2,
                'newDate' => Carbon::today()->subDay()->toDateString(),
                'newTime' => '08:20',
                'reason' => 'Ajuste desde gestion',
                'source' => 'gestion',
            ])
            ->assertCreated()
            ->json('confirmation_id');

        $rejectedProposalId = $this->withHeader('Authorization', 'Bearer ' . $coordinatorToken)
            ->postJson('/api/modifications/confirmations', [
                'recordId' => $recordBaseId + 3,
                'newDate' => Carbon::today()->subDay()->toDateString(),
                'newTime' => '14:45',
                'reason' => 'Ajuste desde bolsa',
                'source' => 'bolsa',
            ])
            ->assertCreated()
            ->json('confirmation_id');

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->getJson('/api/modifications/confirmations')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->putJson('/api/modifications/confirmations/' . $confirmedProposalId . '/confirm')
            ->assertOk()
            ->assertJsonPath('message', 'Cambio aprobado y fichaje actualizado');

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->putJson('/api/modifications/confirmations/' . $rejectedProposalId . '/reject')
            ->assertOk()
            ->assertJsonPath('message', 'Cambio rechazado, fichaje sin modificar');

        $this->assertDatabaseHas('modification_confirmations', [
            'id' => $confirmedProposalId,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('modification_confirmations', [
            'id' => $rejectedProposalId,
            'status' => 'rejected',
        ]);
        $this->assertSame(
            Carbon::today()->subDay()->setTime(8, 20)->format('Y-m-d H:i:s'),
            DB::table('records')->where('id', $recordBaseId + 2)->value('timestamp')
        );
        $this->assertSame(
            Carbon::today()->subDay()->setTime(15, 0)->format('Y-m-d H:i:s'),
            DB::table('records')->where('id', $recordBaseId + 3)->value('timestamp')
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employeeId,
            'type' => 'modification_approved',
            'related_id' => $approvedRequestId,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employeeId,
            'type' => 'modification_rejected',
            'related_id' => $rejectedRequestId,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $coordinatorId,
            'type' => 'modification_approved',
            'related_id' => $confirmedProposalId,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $coordinatorId,
            'type' => 'modification_rejected',
            'related_id' => $rejectedProposalId,
        ]);
    }

    private function nextId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}