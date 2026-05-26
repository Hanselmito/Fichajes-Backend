<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BreakModel;
use App\Models\Record;
use App\Models\User;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BreakController extends Controller
{
    public function __construct(
        private readonly LegacyApiAuth $legacyApiAuth,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $employeeId = $request->integer('employeeId', (int) $authUser->id);
        if (! $this->canAccessEmployee($authUser, $employeeId)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $date = $request->string('date', now()->toDateString())->toString();
        $breaks = BreakModel::query()
            ->with('employee:id,name')
            ->where('employee_id', $employeeId)
            ->whereBetween('break_start', [Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay()])
            ->orderByDesc('break_start')
            ->get();

        $totalMinutes = 0;
        $inProgress = null;
        $serialized = $breaks->map(function (BreakModel $break) use (&$totalMinutes, &$inProgress): array {
            $minutes = Carbon::parse($break->break_start)->diffInMinutes($break->break_end ? Carbon::parse($break->break_end) : now());

            $item = [
                'id' => (int) $break->id,
                'record_id' => (int) $break->record_id,
                'employee_id' => (int) $break->employee_id,
                'employee_name' => $break->employee?->name,
                'break_start' => optional($break->break_start)?->format('Y-m-d H:i:s'),
                'break_end' => optional($break->break_end)?->format('Y-m-d H:i:s'),
                'break_type' => $break->break_type,
                'created_at' => optional($break->created_at)?->format('Y-m-d H:i:s'),
                'duration_minutes' => $minutes,
            ];

            if ($break->break_end) {
                $totalMinutes += $minutes;
            } else {
                $item['in_progress'] = true;
                $inProgress = $item;
            }

            return $item;
        })->values();

        return response()->json([
            'success' => true,
            'breaks' => $serialized,
            'total_minutes' => $totalMinutes,
            'total_hours' => round($totalMinutes / 60, 2),
            'in_progress' => $inProgress,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $data = $request->validate([
            'break_type' => ['nullable', 'in:comida,cafe,personal'],
        ]);

        $lastRecord = Record::query()
            ->select(['id', 'type'])
            ->where('employee_id', $authUser->id)
            ->orderByDesc('timestamp')
            ->first();

        if (! $lastRecord || $lastRecord->type !== 'entrada') {
            return response()->json(['success' => false, 'message' => 'Debes fichar entrada primero'], 400);
        }

        $hasBreakInProgress = BreakModel::query()
            ->where('employee_id', $authUser->id)
            ->whereNull('break_end')
            ->exists();

        if ($hasBreakInProgress) {
            return response()->json(['success' => false, 'message' => 'Ya tienes un descanso en curso'], 400);
        }

        $break = BreakModel::query()->create([
            'id' => $this->nextLegacyId('breaks'),
            'record_id' => $lastRecord->id,
            'employee_id' => $authUser->id,
            'break_start' => now(),
            'break_type' => $data['break_type'] ?? 'comida',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Descanso iniciado',
            'break_id' => (int) $break->id,
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $break = BreakModel::query()
            ->where('employee_id', $authUser->id)
            ->whereNull('break_end')
            ->orderByDesc('break_start')
            ->first();

        if (! $break) {
            return response()->json(['success' => false, 'message' => 'No hay descanso en curso'], 404);
        }

        $break->break_end = now();
        $break->save();

        return response()->json([
            'success' => true,
            'message' => 'Descanso finalizado',
            'duration_minutes' => Carbon::parse($break->break_start)->diffInMinutes(Carbon::parse($break->break_end)),
            'break_type' => $break->break_type,
        ]);
    }

    private function canAccessEmployee(User $authUser, int $employeeId): bool
    {
        if ($employeeId === (int) $authUser->id || $authUser->role === 'admin') {
            return true;
        }

        if ($authUser->role !== 'coordinator') {
            return false;
        }

        $target = User::query()->select(['id', 'zone_id'])->find($employeeId);
        if (! $target) {
            return false;
        }

        $zoneScope = $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_all_bolsa');

        return $zoneScope === null || in_array((int) $target->zone_id, $zoneScope, true);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}