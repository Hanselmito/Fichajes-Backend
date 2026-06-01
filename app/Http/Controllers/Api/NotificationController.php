<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function __construct(
        private readonly LegacyApiAuth $legacyApiAuth,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $unreadOnly = $request->boolean('unreadOnly');
        $limit = min(max($request->integer('limit', 50), 1), 100);

        $query = Notification::query()
            ->where('user_id', $authUser->id)
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($unreadOnly) {
            $query->where('is_read', false);
        }

        return response()->json([
            'success' => true,
            'notifications' => $query->get(),
            'unread_count' => Notification::query()
                ->where('user_id', $authUser->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $updated = Notification::query()
            ->where('id', (int) $notification)
            ->where('user_id', $authUser->id)
            ->update(['is_read' => true]);

        if ($updated === 0) {
            return response()->json(['success' => false, 'message' => 'Notificación no encontrada'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Notificación marcada como leída']);
    }

    public function readAll(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $count = Notification::query()
            ->where('user_id', $authUser->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Notificaciones marcadas como leídas',
            'count' => $count,
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        return response()->json([
            'success' => true,
            'settings' => $this->ensureSettings($authUser),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $data = $request->validate([
            'emailEnabled' => ['sometimes', 'boolean'],
            'pushEnabled' => ['sometimes', 'boolean'],
            'missedCheckinEnabled' => ['sometimes', 'boolean'],
            'vacationNotifications' => ['sometimes', 'boolean'],
            'modificationNotifications' => ['sometimes', 'boolean'],
            'emailAddress' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $updates = [];
        $map = [
            'emailEnabled' => 'email_enabled',
            'pushEnabled' => 'push_enabled',
            'missedCheckinEnabled' => 'missed_checkin_enabled',
            'vacationNotifications' => 'vacation_notifications',
            'modificationNotifications' => 'modification_notifications',
            'emailAddress' => 'email_address',
        ];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $updates[$column] = $data[$input];
            }
        }

        if ($updates === []) {
            return response()->json(['success' => false, 'message' => 'No hay campos para actualizar'], 400);
        }

        $settings = $this->ensureSettings($authUser);
        $settings->fill($updates);
        $settings->save();

        return response()->json(['success' => true, 'message' => 'Configuración actualizada']);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $deleted = Notification::query()
            ->where('id', (int) $notification)
            ->where('user_id', $authUser->id)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['success' => false, 'message' => 'Notificación no encontrada'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Notificación eliminada']);
    }

    private function ensureSettings(User $user): NotificationSetting
    {
        $settings = NotificationSetting::query()->where('user_id', $user->id)->first();
        if ($settings) {
            return $settings;
        }

        return NotificationSetting::query()->create([
            'id' => $this->nextLegacyId('notification_settings'),
            'user_id' => $user->id,
            'email_address' => $user->email,
        ]);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}

