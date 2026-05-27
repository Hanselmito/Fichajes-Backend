<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Models\VacationRequest;
use App\Support\LegacyApiAuth;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VacationRequestController extends Controller
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

        $query = DB::table('vacation_requests as vr')
            ->select([
                'vr.*',
                'u.name as employee_name',
                'u.role as employee_role',
                'u.zone_id',
                'z.name as zone_name',
                'a.name as approved_by_name',
            ])
            ->join('users as u', 'vr.employee_id', '=', 'u.id')
            ->leftJoin('zones as z', 'u.zone_id', '=', 'z.id')
            ->leftJoin('users as a', 'vr.approved_by', '=', 'a.id');

        $this->applyListScope($query, $authUser);

        return response()->json([
            'success' => true,
            'requests' => $query->orderByDesc('vr.created_at')->get(),
        ]);
    }

    public function show(Request $request, string $vacationRequest): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $query = DB::table('vacation_requests as vr')
            ->select([
                'vr.*',
                'u.name as employee_name',
                'u.role as employee_role',
                'a.name as approved_by_name',
            ])
            ->join('users as u', 'vr.employee_id', '=', 'u.id')
            ->leftJoin('users as a', 'vr.approved_by', '=', 'a.id')
            ->where('vr.id', (int) $vacationRequest);

        $this->applyListScope($query, $authUser);
        $row = $query->first();

        if (! $row) {
            return response()->json(['success' => false, 'message' => 'Solicitud no encontrada'], 404);
        }

        return response()->json(['success' => true, 'request' => $row]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $data = $request->validate([
            'employee_id' => ['sometimes', 'integer'],
            'type' => ['required', 'in:vacaciones,asuntos_propios'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'reason' => ['nullable', 'string'],
        ]);

        $actorUserId = (int) $authUser->id;
        $requestedEmployeeId = isset($data['employee_id']) ? (int) $data['employee_id'] : 0;
        $employeeId = $actorUserId;

        if ($requestedEmployeeId > 0 && $requestedEmployeeId !== $actorUserId) {
            if ($authUser->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Solo el administrador puede crear solicitudes para otro usuario'], 403);
            }
            $employeeId = $requestedEmployeeId;
        }

        $employee = User::query()->select(['id', 'name', 'role', 'zone_id'])->find($employeeId);
        if (! $employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
        }

        if (! in_array($employee->role, ['employee', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden crear solicitudes para empleados o coordinadores'], 400);
        }

        [$startDate, $endDate, $daysCount] = $this->validateDateRange($data['start_date'], $data['end_date']);
        if ($daysCount === null) {
            return response()->json(['success' => false, 'message' => 'Fecha de inicio debe ser anterior a la de fin'], 400);
        }

        if ($data['type'] === 'asuntos_propios') {
            $availability = $this->asuntosPropiosAvailability($employeeId, (int) $startDate->format('Y'));
            if ($daysCount > $availability['available']) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay suficientes días de asuntos propios disponibles. Disponibles: ' . $availability['available'] . ', solicitados: ' . $daysCount,
                ], 400);
            }
        }

        $requestId = DB::transaction(function () use ($data, $employeeId, $daysCount, $employee, $authUser): int {
            $requestId = $this->nextLegacyId('vacation_requests');

            VacationRequest::query()->create([
                'id' => $requestId,
                'employee_id' => $employeeId,
                'type' => $data['type'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days_count' => $daysCount,
                'reason' => $data['reason'] ?? null,
                'status' => 'pendiente',
            ]);

            $requestLabel = $data['type'] === 'asuntos_propios' ? 'asuntos propios' : 'vacaciones';
            $this->notifyUsers(
                $this->reviewerIdsForEmployee($employee, (int) $authUser->id),
                'vacation_request',
                'Nueva peticion de vacaciones',
                $employee->name . ' ha solicitado ' . $requestLabel . ' del ' . $data['start_date'] . ' al ' . $data['end_date'] . ' (' . $daysCount . ' días).',
                $requestId,
            );

            $this->notifyUsers(
                [(int) $employeeId],
                'vacation_request',
                'Solicitud de vacaciones registrada',
                $this->buildVacationRequestCreatedMessage($employee, $authUser, $data['type'], $data['start_date'], $data['end_date'], $daysCount),
                $requestId,
            );

            return $requestId;
        });

        return response()->json([
            'success' => true,
            'message' => 'Solicitud creada',
            'request' => [
                'id' => $requestId,
                'days_count' => $daysCount,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $vacationRequest): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $row = VacationRequest::query()->find((int) $vacationRequest);
        if (! $row) {
            return response()->json(['success' => false, 'message' => 'Solicitud no encontrada'], 404);
        }

        if ((int) $row->employee_id !== (int) $authUser->id && $authUser->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        if ($row->status !== 'pendiente') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden cancelar solicitudes pendientes'], 400);
        }

        $row->delete();

        return response()->json(['success' => true, 'message' => 'Solicitud cancelada']);
    }

    public function stats(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $employeeId = $request->integer('employeeId', (int) $authUser->id);
        if (! $this->canAccessEmployeeStats($authUser, $employeeId)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $year = (int) $request->string('year', now()->format('Y'))->toString();
        $statsRows = DB::table('vacation_requests')
            ->selectRaw('type, SUM(days_count) as total_days')
            ->where('employee_id', $employeeId)
            ->where('status', 'aprobada')
            ->whereYear('start_date', $year)
            ->groupBy('type')
            ->get();

        $stats = ['vacaciones' => 0, 'asuntos_propios' => 0];
        foreach ($statsRows as $row) {
            $stats[$row->type] = (int) $row->total_days;
        }

        $vacEntitlement = $this->vacationEntitlementForYear($employeeId, $year);
        $apEntitlement = $this->asuntosPropiosEntitlementForYear($employeeId, $year);
        $carriedOver = (int) $vacEntitlement['carried_over'];
        $totalUsed = (int) $stats['vacaciones'];
        $consumedFromCarryover = min($totalUsed, $carriedOver);
        $consumedFromBase = max(0, $totalUsed - $carriedOver);
        $availableVacations = max(0, (int) $vacEntitlement['total_entitled'] - $totalUsed);

        $apUsed = (int) $stats['asuntos_propios'];
        $apCarriedOver = (int) $apEntitlement['carried_over'];
        $apConsumedFromCarry = min($apUsed, $apCarriedOver);
        $apConsumedFromBase = max(0, $apUsed - $apCarriedOver);
        $apCarriedRestante = max(0, $apCarriedOver - $apConsumedFromCarry);
        $apAvailable = max(0, (int) $apEntitlement['total'] - $apUsed);

        return response()->json([
            'success' => true,
            'year' => (string) $year,
            'stats' => $stats,
            'total' => $stats['vacaciones'] + $stats['asuntos_propios'],
            'base_entitlement' => $vacEntitlement['base_entitlement'],
            'carried_over' => $carriedOver,
            'total_entitled' => $vacEntitlement['total_entitled'],
            'consumed_from_carryover' => $consumedFromCarryover,
            'consumed_from_base' => $consumedFromBase,
            'available_vacaciones' => $availableVacations,
            'ap_base' => $apEntitlement['base'],
            'ap_carried_over' => $apCarriedOver,
            'ap_total' => $apEntitlement['total'],
            'ap_consumed_from_carryover' => $apConsumedFromCarry,
            'ap_consumed_from_base' => $apConsumedFromBase,
            'ap_carried_restante' => $apCarriedRestante,
            'ap_available' => $apAvailable,
        ]);
    }

    public function approve(Request $request, string $vacationRequest): JsonResponse
    {
        return $this->resolveDecision($request, (int) $vacationRequest, true);
    }

    public function reject(Request $request, string $vacationRequest): JsonResponse
    {
        return $this->resolveDecision($request, (int) $vacationRequest, false);
    }

    private function resolveDecision(Request $request, int $requestId, bool $approve): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $row = DB::table('vacation_requests as vr')
            ->select(['vr.*', 'u.zone_id', 'u.role as employee_role'])
            ->join('users as u', 'vr.employee_id', '=', 'u.id')
            ->where('vr.id', $requestId)
            ->first();

        if (! $row) {
            return response()->json(['success' => false, 'message' => 'Solicitud no encontrada'], 404);
        }

        if ($authUser->role === 'coordinator' && ($row->employee_role !== 'employee' || (int) $row->zone_id !== (int) $authUser->zone_id)) {
            return response()->json(['success' => false, 'message' => $approve ? 'No puedes aprobar esta solicitud' : 'No puedes rechazar esta solicitud'], 403);
        }

        $rejectionReason = $request->input('reason');
        VacationRequest::query()->where('id', $requestId)->update([
            'status' => $approve ? 'aprobada' : 'rechazada',
            'approved_by' => $authUser->id,
            'approved_at' => now(),
            'rejection_reason' => $approve ? null : $rejectionReason,
        ]);

        $reasonText = ! empty($rejectionReason) ? ' Motivo: ' . $rejectionReason : '';
        $this->notifyUsers(
            [(int) $row->employee_id],
            $approve ? 'vacation_approved' : 'vacation_rejected',
            $approve ? 'Vacaciones aprobadas' : 'Vacaciones rechazadas',
            $approve
                ? 'Tu solicitud de ' . $row->type . ' del ' . $this->formatDate($row->start_date) . ' al ' . $this->formatDate($row->end_date) . ' ha sido aprobada.'
                : 'Tu solicitud de ' . $row->type . ' del ' . $this->formatDate($row->start_date) . ' al ' . $this->formatDate($row->end_date) . ' ha sido rechazada.' . $reasonText,
            $requestId,
        );

        return response()->json(['success' => true, 'message' => $approve ? 'Solicitud aprobada' : 'Solicitud rechazada']);
    }

    private function applyListScope(Builder $query, User $authUser): void
    {
        if ($authUser->role === 'employee') {
            $query->where('vr.employee_id', $authUser->id);
            return;
        }

        if ($authUser->role !== 'coordinator') {
            return;
        }

        if ($this->legacyApiAuth->userHasAccess($authUser, 'can_view_all_vacations')) {
            $query->where('u.role', '!=', 'admin');
            $zoneScope = $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_all_vacations');
            if ($zoneScope === []) {
                $query->whereRaw('1 = 0');
            } elseif ($zoneScope !== null) {
                $query->whereIn('u.zone_id', $zoneScope);
            }

            return;
        }

        $query->where(function (Builder $scope) use ($authUser): void {
            $scope->where('vr.employee_id', $authUser->id)
                ->orWhere('u.zone_id', $authUser->zone_id);
        });
    }

    private function validateDateRange(string $startDate, string $endDate): array
    {
        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);
        if ($start > $end) {
            return [$start, $end, null];
        }

        return [$start, $end, (int) $start->diff($end)->days + 1];
    }

    private function canAccessEmployeeStats(User $authUser, int $employeeId): bool
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

        $zoneScope = $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_all_vacations');

        return $zoneScope === null || in_array((int) $target->zone_id, $zoneScope, true);
    }

    private function reviewerIdsForEmployee(User $employee, int $excludeUserId): array
    {
        $reviewerIds = User::query()
            ->where('active', 1)
            ->where('role', 'admin')
            ->where('id', '!=', $excludeUserId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($employee->role === 'employee' && $employee->zone_id) {
            $reviewerIds = array_merge($reviewerIds, User::query()
                ->where('active', 1)
                ->where('role', 'coordinator')
                ->where('zone_id', $employee->zone_id)
                ->where('id', '!=', $excludeUserId)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all());
        }

        return array_values(array_unique($reviewerIds));
    }

    private function notifyUsers(array $userIds, string $type, string $title, string $message, ?int $relatedId = null): void
    {
        foreach (array_values(array_unique(array_filter(array_map('intval', $userIds)))) as $userId) {
            Notification::query()->create([
                'id' => $this->nextLegacyId('notifications'),
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'related_id' => $relatedId,
                'related_type' => 'vacation',
                'is_read' => false,
            ]);
        }
    }

    private function buildVacationRequestCreatedMessage(User $employee, User $authUser, string $type, string $startDate, string $endDate, int $daysCount): string
    {
        $requestLabel = $type === 'asuntos_propios' ? 'asuntos propios' : 'vacaciones';
        if ((int) $employee->id === (int) $authUser->id) {
            return 'Has solicitado ' . $requestLabel . ' del ' . $startDate . ' al ' . $endDate . ' (' . $daysCount . ' días).';
        }

        return 'Se ha registrado una solicitud de ' . $requestLabel . ' para ti del ' . $startDate . ' al ' . $endDate . ' (' . $daysCount . ' días).';
    }

    private function asuntosPropiosAvailability(int $employeeId, int $year): array
    {
        $entitlement = $this->asuntosPropiosEntitlementForYear($employeeId, $year);
        $used = (int) DB::table('vacation_requests')
            ->where('employee_id', $employeeId)
            ->where('type', 'asuntos_propios')
            ->where('status', 'aprobada')
            ->whereYear('start_date', $year)
            ->sum('days_count');
        $pending = (int) DB::table('vacation_requests')
            ->where('employee_id', $employeeId)
            ->where('type', 'asuntos_propios')
            ->where('status', 'pendiente')
            ->whereYear('start_date', $year)
            ->sum('days_count');

        return [
            'available' => max(0, (int) $entitlement['total'] - $used - $pending),
            'used' => $used,
            'pending' => $pending,
            'entitlement' => $entitlement,
        ];
    }

    private function asuntosPropiosEntitlementForYear(int $employeeId, int $year): array
    {
        $base = 4;
        $startYear = $this->asuntosPropiosStartYear($employeeId);

        if ($year < $startYear) {
            return ['base' => $base, 'carried_over' => 0, 'total' => 0];
        }

        $carriedOver = 0;
        if ($year > $startYear) {
            $previous = $this->asuntosPropiosEntitlementForYear($employeeId, $year - 1);
            $previousUsed = (int) DB::table('vacation_requests')
                ->where('employee_id', $employeeId)
                ->where('type', 'asuntos_propios')
                ->where('status', 'aprobada')
                ->whereYear('start_date', $year - 1)
                ->sum('days_count');
            $carriedOver = max(0, (int) $previous['total'] - $previousUsed);
        }

        return ['base' => $base, 'carried_over' => $carriedOver, 'total' => $base + $carriedOver];
    }

    private function asuntosPropiosStartYear(int $employeeId): int
    {
        $createdAt = User::query()->where('id', $employeeId)->value('created_at');
        $createdYear = $createdAt ? (int) date('Y', strtotime((string) $createdAt)) : (int) date('Y');
        $earliestYear = (int) DB::table('vacation_requests')
            ->where('employee_id', $employeeId)
            ->where('type', 'asuntos_propios')
            ->min(DB::raw('YEAR(start_date)'));

        return $earliestYear > 0 && $earliestYear < $createdYear ? $earliestYear : $createdYear;
    }

    private function vacationEntitlementForYear(int $employeeId, int $year): array
    {
        $base = 22;
        $startYear = $this->vacationStartYear($employeeId);

        if ($year < $startYear) {
            return ['base_entitlement' => $base, 'carried_over' => 0, 'total_entitled' => 0];
        }

        $carriedOver = 0;
        if ($year > $startYear) {
            $previous = $this->vacationEntitlementForYear($employeeId, $year - 1);
            $previousUsed = (int) DB::table('vacation_requests')
                ->where('employee_id', $employeeId)
                ->where('type', 'vacaciones')
                ->where('status', 'aprobada')
                ->whereYear('start_date', $year - 1)
                ->sum('days_count');
            $carriedOver = max(0, (int) $previous['total_entitled'] - $previousUsed);
        }

        return ['base_entitlement' => $base, 'carried_over' => $carriedOver, 'total_entitled' => $base + $carriedOver];
    }

    private function vacationStartYear(int $employeeId): int
    {
        $createdAt = User::query()->where('id', $employeeId)->value('created_at');
        $createdYear = $createdAt ? (int) date('Y', strtotime((string) $createdAt)) : (int) date('Y');
        $earliestYear = (int) DB::table('vacation_requests')
            ->where('employee_id', $employeeId)
            ->where('type', 'vacaciones')
            ->min(DB::raw('YEAR(start_date)'));

        return $earliestYear > 0 && $earliestYear < $createdYear ? $earliestYear : $createdYear;
    }

    private function formatDate(mixed $date): string
    {
        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}