<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vacation;
use App\Support\LegacyApiAuth;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VacationController extends Controller
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

        $query = DB::table('vacations as v')
            ->select([
                'v.*',
                'e.name as employee_name',
                'a.name as approved_by_name',
                DB::raw('DATEDIFF(v.start_date, CURDATE()) as days_until_start'),
                DB::raw('DATEDIFF(v.end_date, v.start_date) + 1 as total_days'),
            ])
            ->join('users as e', 'v.employee_id', '=', 'e.id')
            ->leftJoin('users as a', 'v.approved_by', '=', 'a.id');

        $this->applyRoleScope($query, $authUser);

        return response()->json([
            'success' => true,
            'vacations' => $query->orderByDesc('v.created_at')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $data = $request->validate([
            'employeeId' => ['nullable', 'integer'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
            'type' => ['nullable', 'in:vacation,sick_leave,permission,other'],
            'reason' => ['nullable', 'string'],
        ]);

        $employeeId = $authUser->role === 'employee'
            ? (int) $authUser->id
            : (int) ($data['employeeId'] ?? 0);

        if ($employeeId <= 0) {
            return response()->json(['success' => false, 'message' => 'Empleado requerido'], 400);
        }

        $employee = User::query()->select(['id', 'zone_id'])->find($employeeId);
        if (! $employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
        }

        if ($authUser->role === 'coordinator' && (int) $employee->zone_id !== (int) $authUser->zone_id) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        if (strtotime((string) $data['endDate']) < strtotime((string) $data['startDate'])) {
            return response()->json(['success' => false, 'message' => 'Fecha fin debe ser mayor que fecha inicio'], 400);
        }

        $overlapExists = DB::table('vacations')
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->where(function (Builder $query) use ($data): void {
                $query
                    ->where(function (Builder $scope) use ($data): void {
                        $scope->where('start_date', '<=', $data['startDate'])
                            ->where('end_date', '>=', $data['startDate']);
                    })
                    ->orWhere(function (Builder $scope) use ($data): void {
                        $scope->where('start_date', '<=', $data['endDate'])
                            ->where('end_date', '>=', $data['endDate']);
                    })
                    ->orWhere(function (Builder $scope) use ($data): void {
                        $scope->where('start_date', '>=', $data['startDate'])
                            ->where('end_date', '<=', $data['endDate']);
                    });
            })
            ->exists();

        if ($overlapExists) {
            return response()->json(['success' => false, 'message' => 'Ya tienes vacaciones aprobadas en estas fechas'], 400);
        }

        $vacationId = $this->nextLegacyId('vacations');
        Vacation::query()->create([
            'id' => $vacationId,
            'employee_id' => $employeeId,
            'start_date' => $data['startDate'],
            'end_date' => $data['endDate'],
            'type' => $data['type'] ?? 'vacation',
            'reason' => $data['reason'] ?? '',
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud de vacaciones enviada',
            'vacation_id' => $vacationId,
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $query = DB::table('vacations as v')
            ->select([
                'v.*',
                'e.name as employee_name',
                'a.name as approved_by_name',
                DB::raw('DATEDIFF(v.start_date, CURDATE()) as days_until_start'),
                DB::raw('DATEDIFF(v.end_date, v.start_date) + 1 as total_days'),
            ])
            ->join('users as e', 'v.employee_id', '=', 'e.id')
            ->leftJoin('users as a', 'v.approved_by', '=', 'a.id')
            ->where('v.id', (int) $id);

        $this->applyRoleScope($query, $authUser);
        $vacation = $query->first();

        if (! $vacation) {
            return response()->json(['success' => false, 'message' => 'Vacaciones no encontradas'], 404);
        }

        return response()->json(['success' => true, 'vacation' => $vacation]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $action = trim((string) $request->query('action', ''));
        if ($action === 'approve') {
            return $this->approve($request, $id);
        }
        if ($action === 'reject') {
            return $this->reject($request, $id);
        }

        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }
        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $vacation = $this->managedVacation($authUser, (int) $id);
        if ($vacation instanceof JsonResponse) {
            return $vacation;
        }

        $data = $request->validate([
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
            'type' => ['sometimes', 'in:vacation,sick_leave,permission,other'],
            'reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:pending,approved,rejected'],
        ]);

        if ($data === []) {
            return response()->json(['success' => false, 'message' => 'Nada que actualizar'], 400);
        }

        if (isset($data['start_date'], $data['end_date']) && strtotime($data['end_date']) < strtotime($data['start_date'])) {
            return response()->json(['success' => false, 'message' => 'Fecha fin debe ser mayor que fecha inicio'], 400);
        }

        if (isset($data['status']) && $data['status'] !== 'pending') {
            $data['approved_by'] = $authUser->id;
            $data['approved_at'] = now();
        }

        $vacation->fill($data)->save();

        return response()->json(['success' => true, 'message' => 'Vacaciones actualizadas']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $query = Vacation::query()->where('id', (int) $id);

        if ($authUser->role === 'employee') {
            $query->where('employee_id', $authUser->id)->where('status', 'pending');
        } elseif ($authUser->role === 'coordinator') {
            $query->whereIn('employee_id', User::query()->where('zone_id', $authUser->zone_id)->pluck('id'));
        }

        $deleted = $query->delete();
        if ($deleted === 0) {
            return response()->json(['success' => false, 'message' => $authUser->role === 'employee' ? 'No se puede cancelar' : 'Vacaciones no encontradas'], $authUser->role === 'employee' ? 400 : 404);
        }

        return response()->json(['success' => true, 'message' => $authUser->role === 'employee' ? 'Solicitud cancelada' : 'Vacaciones eliminadas']);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->resolveDecision($request, (int) $id, true);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->resolveDecision($request, (int) $id, false);
    }

    private function resolveDecision(Request $request, int $vacationId, bool $approve): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }
        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $vacation = $this->managedVacation($authUser, $vacationId);
        if ($vacation instanceof JsonResponse) {
            return $vacation;
        }
        if ($vacation->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Vacaciones no encontradas'], 404);
        }

        $notes = (string) $request->input('notes', $request->input('reason', $approve ? '' : 'Sin motivo especificado'));

        $vacation->fill([
            'status' => $approve ? 'approved' : 'rejected',
            'approved_by' => $authUser->id,
            'approved_at' => now(),
            'notes' => $notes,
        ])->save();

        return response()->json(['success' => true, 'message' => $approve ? 'Vacaciones aprobadas' : 'Vacaciones rechazadas']);
    }

    private function applyRoleScope(Builder $query, User $authUser): void
    {
        if ($authUser->role === 'employee') {
            $query->where('v.employee_id', $authUser->id);
            return;
        }

        if ($authUser->role === 'coordinator') {
            $query->where('e.zone_id', $authUser->zone_id);
        }
    }

    private function managedVacation(User $authUser, int $vacationId): Vacation|JsonResponse
    {
        $query = Vacation::query()->where('id', $vacationId);
        if ($authUser->role === 'coordinator') {
            $query->whereIn('employee_id', User::query()->where('zone_id', $authUser->zone_id)->pluck('id'));
        }

        $vacation = $query->first();
        if (! $vacation) {
            return response()->json(['success' => false, 'message' => 'Vacaciones no encontradas'], 404);
        }

        return $vacation;
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}

