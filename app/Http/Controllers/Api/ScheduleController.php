<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeSchedule;
use App\Models\Record;
use App\Models\User;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class ScheduleController extends Controller
{
    public function __construct(private readonly LegacyApiAuth $legacyApiAuth)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        if ($request->is('api/schedules/status/today')) {
            return $this->todayStatus($request, $authUser);
        }

        $employeeId = $request->integer('employeeId', $authUser->id);

        return $this->show($request, (string) $employeeId);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['required', 'integer'],
            'schedules' => ['required', 'array', 'min:1'],
            'schedules.*.dayOfWeek' => ['required', 'integer', 'between:1,7'],
            'schedules.*.startTime' => ['nullable', 'date_format:H:i'],
            'schedules.*.endTime' => ['nullable', 'date_format:H:i'],
            'schedules.*.isWorkingDay' => ['required', 'boolean'],
            'schedules.*.toleranceMinutes' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos incompletos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $employee = User::query()->find($data['employeeId']);

        if (! $employee) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        if ($authUser->role === 'coordinator' && (int) $employee->zone_id !== (int) $authUser->zone_id) {
            return $this->forbidden('Sin permisos');
        }

        EmployeeSchedule::query()->where('employee_id', $employee->id)->delete();

        foreach ($data['schedules'] as $schedule) {
            EmployeeSchedule::query()->create([
                'id' => $this->nextLegacyId('employee_schedules'),
                'employee_id' => $employee->id,
                'day_of_week' => $schedule['dayOfWeek'],
                'start_time' => $schedule['startTime'] ?? null,
                'end_time' => $schedule['endTime'] ?? null,
                'is_working_day' => (bool) $schedule['isWorkingDay'],
                'entry_tolerance_minutes' => (int) ($schedule['toleranceMinutes'] ?? 15),
                'exit_tolerance_minutes' => (int) ($schedule['toleranceMinutes'] ?? 15),
                'daily_hours' => $this->calculateDailyHours($schedule['startTime'] ?? null, $schedule['endTime'] ?? null, (bool) $schedule['isWorkingDay']),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Horario configurado',
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        $employeeId = (int) $id;
        if ($authUser->role === 'employee' && (int) $authUser->id !== $employeeId) {
            return $this->forbidden('Sin permisos');
        }

        if ($authUser->role === 'coordinator') {
            $employee = User::query()->find($employeeId);
            if (! $employee || (int) $employee->zone_id !== (int) $authUser->zone_id) {
                return $this->forbidden('Sin permisos');
            }
        }

        $schedules = EmployeeSchedule::query()
            ->where('employee_id', $employeeId)
            ->orderBy('day_of_week')
            ->get();

        return response()->json([
            'success' => true,
            'schedules' => $schedules,
        ]);
    }

    private function todayStatus(Request $request, User $authUser): JsonResponse
    {
        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $today = Carbon::today();
        $dayOfWeek = (int) $today->dayOfWeekIso;

        $usersQuery = User::query()
            ->select(['id', 'name', 'zone_id', 'role'])
            ->whereIn('role', ['employee', 'coordinator'])
            ->where('active', true)
            ->with(['zone:id,name']);

        if ($authUser->role === 'coordinator') {
            $usersQuery->where('zone_id', $authUser->zone_id);
        }

        $users = $usersQuery->orderBy('name')->get();

        $statuses = $users->map(function (User $user) use ($today, $dayOfWeek): array {
            $schedule = EmployeeSchedule::query()
                ->where('employee_id', $user->id)
                ->where('day_of_week', $dayOfWeek)
                ->first();

            $lastRecord = Record::query()
                ->where('employee_id', $user->id)
                ->whereDate('timestamp', $today->toDateString())
                ->latest('timestamp')
                ->first();

            return [
                'employee_id' => $user->id,
                'employee_name' => $user->name,
                'zone_id' => $user->zone_id,
                'zone_name' => $user->zone?->name,
                'day_of_week' => $dayOfWeek,
                'is_working_day' => (bool) ($schedule?->is_working_day ?? false),
                'start_time' => $schedule?->start_time,
                'end_time' => $schedule?->end_time,
                'tolerance_minutes' => $schedule?->entry_tolerance_minutes,
                'last_record_type' => $lastRecord?->type,
                'last_record_at' => $lastRecord?->timestamp,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'statuses' => $statuses,
        ]);
    }

    private function calculateDailyHours(?string $startTime, ?string $endTime, bool $isWorkingDay): ?float
    {
        if (! $isWorkingDay || ! $startTime || ! $endTime) {
            return null;
        }

        $start = Carbon::createFromFormat('H:i', $startTime);
        $end = Carbon::createFromFormat('H:i', $endTime);

        return round($start->diffInMinutes($end) / 60, 2);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) \Illuminate\Support\Facades\DB::table($table)->max('id')) + 1;
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
