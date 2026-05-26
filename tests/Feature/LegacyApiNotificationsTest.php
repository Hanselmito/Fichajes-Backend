<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyApiNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_list_read_delete_notifications_and_manage_settings(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
        ])->json('token');

        $adminId = (int) DB::table('users')->where('username', 'admin')->value('id');
        $otherUserId = (int) DB::table('users')->where('id', '!=', $adminId)->value('id');
        if (! $otherUserId) {
            $otherUserId = $adminId;
        }

        $notificationId = $this->nextId('notifications');
        DB::table('notifications')->insert([
            [
                'id' => $notificationId,
                'user_id' => $adminId,
                'type' => 'modification_requested',
                'title' => 'Pendiente',
                'message' => 'Notificación pendiente',
                'related_id' => 1,
                'related_type' => 'modification',
                'is_read' => 0,
            ],
            [
                'id' => $notificationId + 1,
                'user_id' => $adminId,
                'type' => 'modification_approved',
                'title' => 'Leída',
                'message' => 'Notificación leída',
                'related_id' => 2,
                'related_type' => 'modification',
                'is_read' => 1,
            ],
            [
                'id' => $notificationId + 2,
                'user_id' => $otherUserId,
                'type' => 'modification_rejected',
                'title' => 'Ajena',
                'message' => 'No debe aparecer',
                'related_id' => 3,
                'related_type' => 'modification',
                'is_read' => 0,
            ],
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/notifications?unreadOnly=true&limit=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $notificationId);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/notifications/' . $notificationId . '/read')
            ->assertOk()
            ->assertJsonPath('message', 'Notificación marcada como leída');

        $this->assertEquals(1, (int) DB::table('notifications')->where('id', $notificationId)->value('is_read'));

        DB::table('notifications')->where('id', $notificationId + 1)->update(['is_read' => 0]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('count', 1);

        $settingsResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/notifications/settings')
            ->assertOk()
            ->assertJsonPath('success', true);

        $settingsId = (int) $settingsResponse->json('settings.id');
        $this->assertGreaterThan(0, $settingsId);
        $this->assertSame($adminId, (int) $settingsResponse->json('settings.user_id'));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/notifications/settings', [
                'emailEnabled' => false,
                'pushEnabled' => true,
                'missedCheckinEnabled' => false,
                'vacationNotifications' => false,
                'modificationNotifications' => true,
                'emailAddress' => 'admin-notify@test.local',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Configuración actualizada');

        $this->assertDatabaseHas('notification_settings', [
            'id' => $settingsId,
            'user_id' => $adminId,
            'email_enabled' => 0,
            'push_enabled' => 1,
            'missed_checkin_enabled' => 0,
            'vacation_notifications' => 0,
            'modification_notifications' => 1,
            'email_address' => 'admin-notify@test.local',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/notifications/' . $notificationId)
            ->assertOk()
            ->assertJsonPath('message', 'Notificación eliminada');

        $this->assertDatabaseMissing('notifications', [
            'id' => $notificationId,
            'user_id' => $adminId,
        ]);
    }

    private function nextId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}