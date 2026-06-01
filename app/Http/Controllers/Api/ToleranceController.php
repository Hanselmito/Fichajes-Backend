<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ZoneToleranceSetting;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ToleranceController extends Controller
{
    public function __construct(
        private readonly LegacyApiAuth $legacyApiAuth,
    ) {
    }

    public function showZone(Request $request): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $zoneId = $authUser->role === 'coordinator' ? (int) $authUser->zone_id : $request->integer('zoneId');
        if ($zoneId <= 0) {
            return response()->json(['success' => false, 'message' => 'Zone ID requerido'], 400);
        }

        return response()->json(['success' => true, 'settings' => $this->ensureZoneSettings($zoneId)]);
    }

    public function updateZone(Request $request): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $zoneId = $authUser->role === 'coordinator' ? (int) $authUser->zone_id : (int) $request->input('zoneId', 0);
        if ($zoneId <= 0) {
            return response()->json(['success' => false, 'message' => 'Zone ID requerido'], 400);
        }

        $fields = [];
        if ($request->has('defaultEntryTolerance')) {
            $fields['default_entry_tolerance'] = (int) $request->input('defaultEntryTolerance');
        }
        if ($request->has('defaultExitTolerance')) {
            $fields['default_exit_tolerance'] = (int) $request->input('defaultExitTolerance');
        }
        if ($request->has('notifyCoordinator')) {
            $fields['notify_coordinator'] = $request->boolean('notifyCoordinator');
        }
        if ($request->has('notifyAfterMinutes')) {
            $fields['notify_after_minutes'] = (int) $request->input('notifyAfterMinutes');
        }

        if ($fields === []) {
            return response()->json(['success' => false, 'message' => 'No hay campos para actualizar'], 400);
        }

        $this->ensureZoneSettings($zoneId);
        ZoneToleranceSetting::query()->where('zone_id', $zoneId)->update($fields);

        return response()->json(['success' => true, 'message' => 'Configuración actualizada']);
    }

    public function showEmployee(Request $request, string $employeeId): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $employee = $this->resolveEmployeeForManager($authUser, (int) $employeeId);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $schedules = DB::table('employee_schedules as es')
            ->select([
                'es.day_of_week',
                'es.entry_tolerance_minutes',
                'es.exit_tolerance_minutes',
                'es.start_time',
                'es.end_time',
                'es.is_working_day',
                'zts.default_entry_tolerance as zone_default_entry',
                'zts.default_exit_tolerance as zone_default_exit',
            ])
            ->join('users as u', 'es.employee_id', '=', 'u.id')
            ->leftJoin('zone_tolerance_settings as zts', 'u.zone_id', '=', 'zts.zone_id')
            ->where('es.employee_id', $employee->id)
            ->orderBy('es.day_of_week')
            ->get();

        return response()->json(['success' => true, 'schedules' => $schedules]);
    }

    public function updateEmployee(Request $request, string $employeeId): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $employee = $this->resolveEmployeeForManager($authUser, (int) $employeeId);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $dayOfWeek = $request->input('dayOfWeek');
        if ($dayOfWeek === null) {
            return response()->json(['success' => false, 'message' => 'dayOfWeek requerido'], 400);
        }

        $fields = [];
        if ($request->has('entryTolerance')) {
            $fields['entry_tolerance_minutes'] = (int) $request->input('entryTolerance');
        }
        if ($request->has('exitTolerance')) {
            $fields['exit_tolerance_minutes'] = (int) $request->input('exitTolerance');
        }

        if ($fields === []) {
            return response()->json(['success' => false, 'message' => 'No hay campos para actualizar'], 400);
        }

        DB::table('employee_schedules')
            ->where('employee_id', $employee->id)
            ->where('day_of_week', (int) $dayOfWeek)
            ->update($fields);

        return response()->json(['success' => true, 'message' => 'Tolerancia actualizada']);
    }

    public function updateEmployeeAll(Request $request, string $employeeId): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $employee = $this->resolveEmployeeForManager($authUser, (int) $employeeId);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $fields = [];
        if ($request->has('entryTolerance')) {
            $fields['entry_tolerance_minutes'] = (int) $request->input('entryTolerance');
        }
        if ($request->has('exitTolerance')) {
            $fields['exit_tolerance_minutes'] = (int) $request->input('exitTolerance');
        }

        if ($fields === []) {
            return response()->json(['success' => false, 'message' => 'No hay campos para actualizar'], 400);
        }

        $rows = DB::table('employee_schedules')
            ->where('employee_id', $employee->id)
            ->update($fields);

        return response()->json(['success' => true, 'message' => 'Tolerancia actualizada para ' . $rows . ' días']);
    }

    public function presets(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        return response()->json([
            'success' => true,
            'presets' => [
                ['name' => 'Estricto', 'description' => 'Sin tolerancia', 'entryTolerance' => 0, 'exitTolerance' => 0],
                ['name' => 'Normal (5 min)', 'description' => '5 minutos de margen', 'entryTolerance' => 5, 'exitTolerance' => 5],
                ['name' => 'Flexible (15 min)', 'description' => '15 minutos de margen', 'entryTolerance' => 15, 'exitTolerance' => 15],
                ['name' => 'Muy flexible (30 min)', 'description' => '30 minutos de margen', 'entryTolerance' => 30, 'exitTolerance' => 30],
                ['name' => 'Personalizado', 'description' => 'Configura tus propios valores', 'entryTolerance' => null, 'exitTolerance' => null],
            ],
        ]);
    }

    private function requireManager(Request $request): User|JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        return $authUser;
    }

    private function resolveEmployeeForManager(User $authUser, int $employeeId): User|JsonResponse
    {
        $employee = User::query()->select(['id', 'zone_id'])->find($employeeId);
        if (! $employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
        }

        if ($authUser->role === 'coordinator' && (int) $employee->zone_id !== (int) $authUser->zone_id) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        return $employee;
    }

    private function ensureZoneSettings(int $zoneId): array
    {
        $settings = DB::table('zone_tolerance_settings as zts')
            ->select(['zts.*', 'z.name as zone_name'])
            ->join('zones as z', 'zts.zone_id', '=', 'z.id')
            ->where('zts.zone_id', $zoneId)
            ->first();

        if ($settings) {
            return (array) $settings;
        }

        ZoneToleranceSetting::query()->create([
            'id' => ((int) DB::table('zone_tolerance_settings')->max('id')) + 1,
            'zone_id' => $zoneId,
            'default_entry_tolerance' => 15,
            'default_exit_tolerance' => 15,
            'notify_coordinator' => true,
            'notify_after_minutes' => 0,
        ]);

        return (array) DB::table('zone_tolerance_settings as zts')
            ->select(['zts.*', 'z.name as zone_name'])
            ->join('zones as z', 'zts.zone_id', '=', 'z.id')
            ->where('zts.zone_id', $zoneId)
            ->first();
    }
}
