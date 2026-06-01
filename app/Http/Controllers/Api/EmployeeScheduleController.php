<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Support\LegacyApiAuth;
use App\Support\LegacyScheduleHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EmployeeScheduleController extends Controller
{
    private const DAY_NAMES = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];

    public function __construct(
        private readonly LegacyApiAuth $legacyApiAuth,
        private readonly LegacyScheduleHistory $scheduleHistory,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $employeeId = $request->integer('employeeId', (int) $authUser->id);
        if (! $this->canAccessEmployee($authUser, $employeeId)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $snapshot = $this->scheduleHistory->loadCurrentSnapshot($employeeId);
        $schedules = collect($snapshot['days'])
            ->map(fn (array $schedule): array => [
                'id' => $this->findCurrentScheduleId($employeeId, (int) $schedule['day_of_week']),
                'employee_id' => $employeeId,
                'day_of_week' => self::DAY_NAMES[(int) $schedule['day_of_week']] ?? 'desconocido',
                'is_workday' => (int) $schedule['is_working_day'],
                'shift_start_1' => $schedule['segments'][0]['start_time'] ?? null,
                'shift_end_1' => $schedule['segments'][0]['end_time'] ?? null,
                'shift_start_2' => $schedule['segments'][1]['start_time'] ?? null,
                'shift_end_2' => $schedule['segments'][1]['end_time'] ?? null,
                'segments' => $schedule['segments'],
                'daily_hours' => $schedule['daily_hours'],
            ])
            ->values();

        return response()->json(['success' => true, 'schedules' => $schedules]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request);
    }

    public function update(Request $request): JsonResponse
    {
        return $this->save($request);
    }

    private function save(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['required', 'integer'],
            'effective_date' => ['nullable', 'date'],
            'weekly_hours' => ['nullable', 'numeric', 'min:0'],
            'schedules' => ['required', 'array', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Datos incompletos', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $employeeId = (int) $data['employeeId'];
        if (! $this->canAccessEmployee($authUser, $employeeId)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $employee = User::query()->find($employeeId);
        if (! $employee) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        $normalizedSchedules = $this->scheduleHistory->normalizeSchedules($data['schedules']);
        $weeklyHours = isset($data['weekly_hours'])
            ? round((float) $data['weekly_hours'], 2)
            : round(array_reduce($normalizedSchedules, static fn (float $sum, array $schedule): float => $sum + (float) $schedule['daily_hours'], 0.0), 2);
        $effectiveDate = $data['effective_date'] ?? now()->toDateString();

        DB::transaction(function () use ($employeeId, $normalizedSchedules, $weeklyHours, $effectiveDate, $authUser, $employee): void {
            $this->scheduleHistory->ensureInitialVersion($employeeId, (int) $authUser->id);
            $this->scheduleHistory->saveVersion($employeeId, $normalizedSchedules, $weeklyHours, $effectiveDate, (int) $authUser->id);
            $this->scheduleHistory->syncCurrentTables($employeeId, $effectiveDate);

            Notification::query()->create([
                'id' => $this->nextLegacyId('notifications'),
                'user_id' => $employeeId,
                'type' => 'modification_approved',
                'title' => 'Horario actualizado',
                'message' => 'Tu horario se ha actualizado con fecha de vigencia ' . $effectiveDate,
                'related_id' => $employeeId,
                'related_type' => 'schedule',
                'is_read' => false,
            ]);

            $employee->weekly_hours = $weeklyHours;
            $employee->save();
        });

        return response()->json(['success' => true, 'message' => 'Horario configurado']);
    }

    private function canAccessEmployee(User $authUser, int $employeeId): bool
    {
        if ($employeeId === (int) $authUser->id || $authUser->role === 'admin') {
            return true;
        }

        if ($authUser->role !== 'coordinator') {
            return false;
        }

        $targetUser = User::query()->select(['id', 'role', 'zone_id'])->find($employeeId);

        return $targetUser !== null
            && $targetUser->role === 'employee'
            && (int) $targetUser->zone_id === (int) $authUser->zone_id;
    }

    private function findCurrentScheduleId(int $employeeId, int $dayOfWeek): ?int
    {
        $value = DB::table('employee_schedules')
            ->where('employee_id', $employeeId)
            ->where('day_of_week', $dayOfWeek)
            ->value('id');

        return $value !== null ? (int) $value : null;
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}
