<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Schedule;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\User;
use App\Support\LegacyApiAuth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class QuadrantController extends Controller
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

        if ($request->boolean('availability')) {
            return $this->availability($request, $authUser);
        }

        if ($request->filled('client_id')) {
            return $this->clientAssignments($request, $authUser);
        }

        if ($request->has('employee')) {
            return $this->employeeAssignments($request, $authUser);
        }

        $query = Schedule::query()
            ->select('schedules.*')
            ->with(['zone:id,name', 'creator:id,name'])
            ->withCount(['assignments as total_assignments' => static function (Builder $query): void {
                $query->where('is_active', true);
            }])
            ->orderByDesc('week_start');

        if ($authUser->role === 'coordinator') {
            $query->where('zone_id', $authUser->zone_id);
        } elseif ($authUser->role === 'employee') {
            $query->whereHas('assignments', static function (Builder $query) use ($authUser): void {
                $query->where('employee_id', $authUser->id)->where('is_active', true);
            });
        }

        if ($request->filled('week_start')) {
            $query->whereDate('week_start', $request->string('week_start')->toString());
        }

        if ($request->filled('zone_id')) {
            $query->where('zone_id', $request->integer('zone_id'));
        }

        $quadrants = $query->get()->map(fn (Schedule $schedule): array => $this->serializeQuadrant($schedule))->values();

        return response()->json([
            'success' => true,
            'quadrants' => $quadrants,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        $quadrant = Schedule::query()
            ->with(['zone:id,name', 'creator:id,name'])
            ->find($id);

        if (! $quadrant) {
            return response()->json([
                'success' => false,
                'message' => 'Cuadrante no encontrado',
            ], 404);
        }

        if (($forbidden = $this->ensureQuadrantAccess($authUser, $quadrant)) !== null) {
            return $forbidden;
        }

        $assignmentsQuery = ScheduleAssignment::query()
            ->with(['employee:id,name', 'client:id,name,address,city,latitude,longitude'])
            ->where('schedule_id', $quadrant->id)
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time');

        if ($authUser->role === 'employee') {
            $assignmentsQuery->where('employee_id', $authUser->id);
        }

        $assignments = $assignmentsQuery->get()->map(fn (ScheduleAssignment $assignment): array => $this->serializeAssignment($assignment))->values();

        return response()->json([
            'success' => true,
            'quadrant' => array_merge($this->serializeQuadrant($quadrant), [
                'assignments' => $assignments,
            ]),
        ]);
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
            'name' => ['required', 'string', 'max:255'],
            'week_start' => ['required', 'date'],
            'zone_id' => ['nullable', 'integer'],
            'is_template' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Nombre y semana son obligatorios',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $zoneId = $authUser->role === 'coordinator' ? (int) $authUser->zone_id : (int) ($data['zone_id'] ?? 0);

        if ($zoneId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'La zona es obligatoria',
            ], 400);
        }

        $quadrant = Schedule::query()->create([
            'id' => $this->nextLegacyId('schedules'),
            'name' => $data['name'],
            'zone_id' => $zoneId,
            'week_start' => $data['week_start'],
            'is_template' => (bool) ($data['is_template'] ?? false),
            'created_by' => $authUser->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cuadrante creado',
            'id' => $quadrant->id,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $quadrant = Schedule::query()->find($id);
        if (! $quadrant) {
            return response()->json([
                'success' => false,
                'message' => 'Cuadrante no encontrado',
            ], 404);
        }

        if (($forbidden = $this->ensureQuadrantAccess($authUser, $quadrant, true)) !== null) {
            return $forbidden;
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'week_start' => ['sometimes', 'date'],
            'is_template' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        if ($data === []) {
            return response()->json([
                'success' => false,
                'message' => 'Nada que actualizar',
            ], 400);
        }

        $quadrant->fill($data)->save();

        return response()->json([
            'success' => true,
            'message' => 'Cuadrante actualizado',
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $quadrant = Schedule::query()->find($id);
        if (! $quadrant) {
            return response()->json([
                'success' => false,
                'message' => 'Cuadrante no encontrado',
            ], 404);
        }

        if (($forbidden = $this->ensureQuadrantAccess($authUser, $quadrant, true)) !== null) {
            return $forbidden;
        }

        $quadrant->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cuadrante eliminado',
        ]);
    }

    public function assignments(Request $request, string $quadrantId): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return $this->unauthorized();
        }

        $quadrant = Schedule::query()->find($quadrantId);
        if (! $quadrant) {
            return response()->json(['success' => false, 'message' => 'Cuadrante no encontrado'], 404);
        }

        if (($forbidden = $this->ensureQuadrantAccess($authUser, $quadrant)) !== null) {
            return $forbidden;
        }

        $query = ScheduleAssignment::query()
            ->with(['employee:id,name', 'client:id,name,address,city,latitude,longitude'])
            ->where('schedule_id', $quadrant->id)
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time');

        if ($authUser->role === 'employee') {
            $query->where('employee_id', $authUser->id);
        }

        return response()->json([
            'success' => true,
            'assignments' => $query->get()->map(fn (ScheduleAssignment $assignment): array => $this->serializeAssignment($assignment))->values(),
        ]);
    }

    public function storeAssignment(Request $request, string $quadrantId): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $quadrant = Schedule::query()->find($quadrantId);
        if (! $quadrant) {
            return response()->json(['success' => false, 'message' => 'Cuadrante no encontrado'], 404);
        }

        if (($forbidden = $this->ensureQuadrantAccess($authUser, $quadrant, true)) !== null) {
            return $forbidden;
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => ['required', 'integer'],
            'client_id' => ['required', 'integer'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'services' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Faltan campos obligatorios',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $serviceContext = $this->validateAssignmentServices($data['client_id'], $data['services'] ?? [], $data['start_time'], $data['end_time']);
        if ($serviceContext !== null && ($serviceContext['error'] ?? null)) {
            return response()->json(['success' => false, 'message' => $serviceContext['error']], 422);
        }

        $assignment = ScheduleAssignment::query()->create([
            'id' => $this->nextLegacyId('schedule_assignments'),
            'schedule_id' => $quadrant->id,
            'employee_id' => $data['employee_id'],
            'client_id' => $data['client_id'],
            'day_of_week' => $data['day_of_week'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'services' => $serviceContext['service_ids'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Asignación creada',
            'id' => $assignment->id,
        ], 201);
    }

    public function updateAssignment(Request $request, string $quadrantId, string $assignmentId): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $assignment = ScheduleAssignment::query()->where('schedule_id', $quadrantId)->find($assignmentId);
        if (! $assignment) {
            return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
        }

        $quadrant = Schedule::query()->find($quadrantId);
        if (($forbidden = $this->ensureQuadrantAccess($authUser, $quadrant, true)) !== null) {
            return $forbidden;
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => ['sometimes', 'integer'],
            'client_id' => ['sometimes', 'integer'],
            'day_of_week' => ['sometimes', 'integer', 'between:1,7'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
            'services' => ['sometimes', 'nullable', 'array'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Datos invalidos', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if ($data === []) {
            return response()->json(['success' => false, 'message' => 'Nada que actualizar'], 400);
        }

        $clientId = (int) ($data['client_id'] ?? $assignment->client_id);
        $startTime = (string) ($data['start_time'] ?? $assignment->start_time);
        $endTime = (string) ($data['end_time'] ?? $assignment->end_time);
        $services = $data['services'] ?? ($assignment->services ?? []);
        $serviceContext = $this->validateAssignmentServices($clientId, is_array($services) ? $services : [], $startTime, $endTime);
        if ($serviceContext !== null && ($serviceContext['error'] ?? null)) {
            return response()->json(['success' => false, 'message' => $serviceContext['error']], 422);
        }

        if (array_key_exists('services', $data)) {
            $data['services'] = $serviceContext['service_ids'] ?? null;
        }

        $assignment->fill($data)->save();

        return response()->json(['success' => true, 'message' => 'Asignación actualizada']);
    }

    public function destroyAssignment(Request $request, string $quadrantId, string $assignmentId): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $assignment = ScheduleAssignment::query()->where('schedule_id', $quadrantId)->find($assignmentId);
        if (! $assignment) {
            return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
        }

        $quadrant = Schedule::query()->find($quadrantId);
        if (($forbidden = $this->ensureQuadrantAccess($authUser, $quadrant, true)) !== null) {
            return $forbidden;
        }

        $assignment->is_active = false;
        $assignment->save();

        return response()->json(['success' => true, 'message' => 'Asignación eliminada']);
    }

    public function exceptions(Request $request, string $quadrantId, string $assignmentId): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return $this->unauthorized();
        }

        $assignment = $this->loadAssignmentWithinQuadrant($quadrantId, $assignmentId);
        if (! $assignment) {
            return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
        }

        if (($forbidden = $this->ensureQuadrantAccess($authUser, $assignment->schedule)) !== null) {
            return $forbidden;
        }

        $exceptions = ScheduleException::query()
            ->with(['substituteEmployee:id,name', 'creator:id,name'])
            ->where('assignment_id', $assignment->id)
            ->orderBy('exception_date')
            ->get()
            ->map(fn (ScheduleException $exception): array => $this->serializeException($exception))
            ->values();

        return response()->json(['success' => true, 'exceptions' => $exceptions]);
    }

    public function storeException(Request $request, string $quadrantId, string $assignmentId): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $assignment = $this->loadAssignmentWithinQuadrant($quadrantId, $assignmentId);
        if (! $assignment) {
            return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
        }

        if (($forbidden = $this->ensureQuadrantAccess($authUser, $assignment->schedule, true)) !== null) {
            return $forbidden;
        }

        $validator = Validator::make($request->all(), [
            'exception_date' => ['required', 'date'],
            'type' => ['required', 'string', 'in:modified,cancelled,substitution'],
            'substitute_employee_id' => ['nullable', 'integer'],
            'new_start_time' => ['nullable', 'date_format:H:i'],
            'new_end_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Fecha y tipo son obligatorios', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $exception = ScheduleException::query()->create([
            'id' => $this->nextLegacyId('schedule_exceptions'),
            'assignment_id' => $assignment->id,
            'exception_date' => $data['exception_date'],
            'type' => $data['type'],
            'substitute_employee_id' => $data['substitute_employee_id'] ?? null,
            'new_start_time' => $data['new_start_time'] ?? null,
            'new_end_time' => $data['new_end_time'] ?? null,
            'reason' => $data['reason'] ?? null,
            'created_by' => $authUser->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Excepción creada',
            'id' => $exception->id,
        ], 201);
    }

    public function destroyException(Request $request, string $quadrantId, string $assignmentId, string $exceptionId): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $assignment = $this->loadAssignmentWithinQuadrant($quadrantId, $assignmentId);
        if (! $assignment) {
            return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
        }

        if (($forbidden = $this->ensureQuadrantAccess($authUser, $assignment->schedule, true)) !== null) {
            return $forbidden;
        }

        $exception = ScheduleException::query()->where('assignment_id', $assignment->id)->find($exceptionId);
        if (! $exception) {
            return response()->json(['success' => false, 'message' => 'Excepción no encontrada'], 404);
        }

        $exception->delete();

        return response()->json(['success' => true, 'message' => 'Excepción eliminada']);
    }

    private function employeeAssignments(Request $request, User $authUser): JsonResponse
    {
        $employeeId = $authUser->role === 'employee'
            ? (int) $authUser->id
            : (int) $request->integer('employeeId', $authUser->id);

        $weekStart = $this->resolveWeekStart($request);

        $assignments = ScheduleAssignment::query()
            ->with(['employee:id,name', 'client:id,name,address,city,phone,latitude,longitude', 'schedule:id,name,week_start'])
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->whereHas('schedule', static function (Builder $query) use ($weekStart): void {
                $query->whereDate('week_start', '<=', $weekStart->toDateString());
            })
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $result = $assignments
            ->map(fn (ScheduleAssignment $assignment): ?array => $this->applyWeekException($assignment, $weekStart, $employeeId))
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'assignments' => $result,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->copy()->addDays(6)->toDateString(),
        ]);
    }

    private function clientAssignments(Request $request, User $authUser): JsonResponse
    {
        $clientId = $request->integer('client_id');
        $weekStart = $this->resolveWeekStart($request);

        $query = ScheduleAssignment::query()
            ->with(['employee:id,name', 'client:id,name,address,city,phone,latitude,longitude', 'schedule:id,name,week_start'])
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->whereHas('schedule', static function (Builder $query) use ($weekStart): void {
                $query->whereDate('week_start', '<=', $weekStart->toDateString());
            })
            ->orderBy('day_of_week')
            ->orderBy('start_time');

        if ($authUser->role === 'employee') {
            $query->where('employee_id', $authUser->id);
        }

        $result = $query->get()
            ->map(fn (ScheduleAssignment $assignment): ?array => $this->applyWeekException($assignment, $weekStart, $authUser->role === 'employee' ? (int) $authUser->id : null))
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'assignments' => $result,
            'accompaniment_status' => $this->getClientCurrentCoverageStatus($clientId, $result),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->copy()->addDays(6)->toDateString(),
        ]);
    }

    private function availability(Request $request, User $authUser): JsonResponse
    {
        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $validator = Validator::make($request->all(), [
            'zone_id' => ['required', 'integer'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'week_start' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Datos incompletos', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $weekStart = isset($data['week_start']) ? Carbon::parse($data['week_start'])->startOfDay() : Carbon::now()->startOfWeek();

        $employees = User::query()
            ->select(['id', 'name'])
            ->where('zone_id', $data['zone_id'])
            ->whereIn('role', ['employee', 'coordinator'])
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $clientCoords = null;
        $requiredServices = collect();
        $requiredServiceIds = [];
        $requiredDuration = 0;
        if (! empty($data['client_id'])) {
            $clientCoords = Client::query()->select(['id', 'latitude', 'longitude'])->find($data['client_id']);
            $requiredServices = $this->loadClientServices((int) $data['client_id']);
            $requiredServiceIds = $requiredServices->pluck('id')->all();
            $requiredDuration = (int) $requiredServices->sum('duration_minutes');
        }

        $slotMinutes = Carbon::createFromFormat('H:i', $data['start_time'])->diffInMinutes(Carbon::createFromFormat('H:i', $data['end_time']));

        $result = $employees->map(function (User $employee) use ($data, $weekStart, $clientCoords): array {
            $visits = ScheduleAssignment::query()
                ->with('client:id,name,address,city,latitude,longitude')
                ->where('employee_id', $employee->id)
                ->where('day_of_week', $data['day_of_week'])
                ->where('is_active', true)
                ->whereHas('schedule', static function (Builder $query) use ($weekStart): void {
                    $query->whereDate('week_start', '<=', $weekStart->toDateString());
                })
                ->orderBy('start_time')
                ->get();

            $conflict = null;
            $previous = null;
            $next = null;

            foreach ($visits as $visit) {
                if ($visit->start_time < $data['end_time'] && $visit->end_time > $data['start_time']) {
                    $conflict = $this->serializeAvailabilityVisit($visit);
                }

                if ($visit->end_time <= $data['start_time']) {
                    $previous = $visit;
                }

                if ($visit->start_time >= $data['end_time'] && $next === null) {
                    $next = $visit;
                }
            }

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'conflicto' => $conflict,
                'visita_anterior' => $previous ? $this->serializeAvailabilityVisit($previous) : null,
                'visita_siguiente' => $next ? $this->serializeAvailabilityVisit($next) : null,
                'min_antes' => $previous ? (strtotime($data['start_time']) - strtotime($previous->end_time)) / 60 : null,
                'min_despues' => $next ? (strtotime($next->start_time) - strtotime($data['end_time'])) / 60 : null,
                'distancia_anterior' => $this->distanceForVisit($previous, $clientCoords),
                'distancia_siguiente' => $this->distanceForVisit($next, $clientCoords),
                'visitas_dia' => $visits->count(),
            ];
        })->values();

        $clients = Client::query()
            ->select(['id', 'name', 'address', 'city'])
            ->where('zone_id', $data['zone_id'])
            ->orderBy('name')
            ->get();

        $busyClient = null;
        if (! empty($data['client_id'])) {
            $busyAssignment = ScheduleAssignment::query()
                ->with('employee:id,name')
                ->where('client_id', $data['client_id'])
                ->where('day_of_week', $data['day_of_week'])
                ->where('is_active', true)
                ->whereHas('schedule', static function (Builder $query) use ($weekStart): void {
                    $query->whereDate('week_start', '<=', $weekStart->toDateString());
                })
                ->where('start_time', '<', $data['end_time'])
                ->where('end_time', '>', $data['start_time'])
                ->first();

            if ($busyAssignment) {
                $busyClient = [
                    'employee_name' => $busyAssignment->employee?->name,
                    'start_time' => $busyAssignment->start_time,
                    'end_time' => $busyAssignment->end_time,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'employees' => $result,
            'clients' => $clients,
            'cliente_ocupado' => $busyClient,
            'required_services' => $requiredServices->map(fn (Service $service): array => $this->serializeService($service))->values(),
            'required_service_ids' => $requiredServiceIds,
            'required_service_duration_minutes' => $requiredDuration,
            'selected_slot_minutes' => $slotMinutes,
            'service_time_fit' => $requiredDuration === 0 ? true : $slotMinutes >= $requiredDuration,
        ]);
    }

    private function serializeQuadrant(Schedule $schedule): array
    {
        return array_merge($schedule->withoutRelations()->toArray(), [
            'zone_name' => $schedule->zone?->name,
            'created_by_name' => $schedule->creator?->name,
        ]);
    }

    private function serializeAssignment(ScheduleAssignment $assignment): array
    {
        $serviceIds = $this->normalizeServiceIds($assignment->services ?? []);
        $services = $this->loadServicesByIds($serviceIds);
        $clientServiceIds = $assignment->client_id ? $this->loadClientServiceIds((int) $assignment->client_id) : [];
        $missingClientServices = array_values(array_diff($clientServiceIds, $serviceIds));

        return array_merge($assignment->withoutRelations()->toArray(), [
            'employee_name' => $assignment->employee?->name,
            'client_name' => $assignment->client?->name,
            'client_address' => $assignment->client?->address,
            'client_city' => $assignment->client?->city,
            'latitude' => $assignment->client?->latitude,
            'longitude' => $assignment->client?->longitude,
            'services' => $serviceIds,
            'service_details' => $services->map(fn (Service $service): array => $this->serializeService($service))->values(),
            'services_duration_minutes' => (int) $services->sum('duration_minutes'),
            'client_service_ids' => $clientServiceIds,
            'missing_client_service_ids' => $missingClientServices,
            'service_coverage_complete' => $missingClientServices === [],
        ]);
    }

    private function serializeException(ScheduleException $exception): array
    {
        return array_merge($exception->withoutRelations()->toArray(), [
            'substitute_name' => $exception->substituteEmployee?->name,
            'created_by_name' => $exception->creator?->name,
        ]);
    }

    private function applyWeekException(ScheduleAssignment $assignment, Carbon $weekStart, ?int $employeeId = null): ?array
    {
        $serialized = $this->serializeAssignment($assignment);
        $dayDate = $weekStart->copy()->addDays(((int) $assignment->day_of_week) - 1)->toDateString();
        $serialized['date'] = $dayDate;

        $exception = ScheduleException::query()
            ->where('assignment_id', $assignment->id)
            ->whereDate('exception_date', $dayDate)
            ->latest('created_at')
            ->first();

        if ($exception) {
            if ($exception->type === 'cancelled') {
                return null;
            }

            if ($exception->type === 'substitution' && $employeeId !== null && (int) $exception->substitute_employee_id !== $employeeId) {
                return null;
            }

            if ($exception->type === 'modified') {
                if ($exception->new_start_time) {
                    $serialized['start_time'] = $exception->new_start_time;
                }

                if ($exception->new_end_time) {
                    $serialized['end_time'] = $exception->new_end_time;
                }
            }

            $serialized['exception'] = $this->serializeException($exception);
        }

        return $serialized;
    }

    private function getClientCurrentCoverageStatus(int $clientId, Collection $assignments): array
    {
        $today = Carbon::today();
        $now = Carbon::now();
        $requiredServices = $this->loadClientServices($clientId);
        $requiredServiceIds = $requiredServices->pluck('id')->all();

        $activeAssignments = $assignments
            ->filter(function (array $assignment) use ($today, $now): bool {
                if (($assignment['date'] ?? null) !== $today->toDateString()) {
                    return false;
                }

                $startAt = Carbon::parse($today->toDateString().' '.substr((string) $assignment['start_time'], 0, 5));
                $endAt = Carbon::parse($today->toDateString().' '.substr((string) $assignment['end_time'], 0, 5));

                return $now->greaterThanOrEqualTo($startAt) && $now->lessThan($endAt);
            })
            ->sortBy('start_time')
            ->values();

        if ($activeAssignments->isEmpty()) {
            return [
                'tone' => 'success',
                'label' => 'Usuario OK',
                'employee_id' => null,
                'employee_name' => null,
                'delay' => '00:00',
            ];
        }

        $employeeIds = $activeAssignments->pluck('employee_id')->filter()->unique()->values();
        $latestRecords = collect();

        if ($employeeIds->isNotEmpty()) {
            $latestRecords = \App\Models\Record::query()
                ->selectRaw('employee_id, MAX(timestamp) as last_timestamp')
                ->where('client_id', $clientId)
                ->whereDate('timestamp', $today->toDateString())
                ->whereIn('employee_id', $employeeIds->all())
                ->groupBy('employee_id')
                ->get()
                ->keyBy('employee_id');
        }

        foreach ($activeAssignments as $assignment) {
            $record = $latestRecords->get($assignment['employee_id']);
            if (! $record?->last_timestamp) {
                continue;
            }

            $recordAt = Carbon::parse($record->last_timestamp);
            $startAt = Carbon::parse($today->toDateString().' '.substr((string) $assignment['start_time'], 0, 5));
            if ($recordAt->lt($startAt)) {
                continue;
            }

            $assignmentServiceIds = $this->normalizeServiceIds($assignment['services'] ?? []);
            $missingServiceIds = array_values(array_diff($requiredServiceIds, $assignmentServiceIds));
            if ($missingServiceIds !== []) {
                $missingServices = $this->loadServicesByIds($missingServiceIds)->pluck('name')->implode(', ');

                return [
                    'tone' => 'warning',
                    'label' => 'Cobertura parcial, faltan servicios: ' . $missingServices,
                    'employee_id' => $assignment['employee_id'] ?? null,
                    'employee_name' => $assignment['employee_name'] ?? null,
                    'delay' => '00:00',
                    'coverage_complete' => false,
                ];
            }

            return [
                'tone' => 'info',
                'label' => 'Usuario acompañado por: '.($assignment['employee_name'] ?? 'Empleado asignado'),
                'employee_id' => $assignment['employee_id'] ?? null,
                'employee_name' => $assignment['employee_name'] ?? null,
                'delay' => '00:00',
                'coverage_complete' => true,
            ];
        }

        $firstAssignment = $activeAssignments->first();
        $startAt = Carbon::parse($today->toDateString().' '.substr((string) $firstAssignment['start_time'], 0, 5));
        $delayMinutes = $startAt->diffInMinutes($now, false);

        if ($delayMinutes < 5) {
            return [
                'tone' => 'success',
                'label' => 'Usuario OK',
                'employee_id' => $firstAssignment['employee_id'] ?? null,
                'employee_name' => $firstAssignment['employee_name'] ?? null,
                'delay' => '00:00',
                'coverage_complete' => $requiredServiceIds === [],
            ];
        }

        return [
            'tone' => 'danger',
            'label' => 'Retraso de '.($firstAssignment['employee_name'] ?? 'Empleado asignado').' por: '.$this->formatDelayMinutesAsClock($delayMinutes),
            'employee_id' => $firstAssignment['employee_id'] ?? null,
            'employee_name' => $firstAssignment['employee_name'] ?? null,
            'delay' => $this->formatDelayMinutesAsClock($delayMinutes),
            'coverage_complete' => false,
        ];
    }

    private function validateAssignmentServices(int $clientId, array $serviceIds, string $startTime, string $endTime): ?array
    {
        $normalizedIds = $this->normalizeServiceIds($serviceIds);
        if ($normalizedIds === []) {
            return ['service_ids' => null];
        }

        $services = $this->loadServicesByIds($normalizedIds);
        if ($services->count() !== count($normalizedIds)) {
            return ['error' => 'Alguno de los servicios seleccionados no existe'];
        }

        $slotMinutes = Carbon::createFromFormat('H:i', substr($startTime, 0, 5))->diffInMinutes(Carbon::createFromFormat('H:i', substr($endTime, 0, 5)));
        $requiredMinutes = (int) $services->sum('duration_minutes');
        if ($requiredMinutes > $slotMinutes) {
            return ['error' => 'La duracion total de los servicios supera el hueco asignado'];
        }

        $clientServiceIds = $this->loadClientServiceIds($clientId);
        $invalidForClient = array_values(array_diff($normalizedIds, $clientServiceIds));
        if ($clientServiceIds !== [] && $invalidForClient !== []) {
            return ['error' => 'La visita incluye servicios no asignados al usuario'];
        }

        return ['service_ids' => $normalizedIds];
    }

    private function normalizeServiceIds(mixed $value): array
    {
        $serviceIds = is_array($value) ? $value : [];

        return array_values(array_unique(array_filter(array_map(static fn ($serviceId): int => (int) $serviceId, $serviceIds), static fn (int $serviceId): bool => $serviceId > 0)));
    }

    private function loadClientServiceIds(int $clientId): array
    {
        return \Illuminate\Support\Facades\DB::table('client_services')
            ->where('client_id', $clientId)
            ->pluck('service_id')
            ->map(static fn ($serviceId): int => (int) $serviceId)
            ->unique()
            ->values()
            ->all();
    }

    private function loadClientServices(int $clientId): Collection
    {
        $serviceIds = $this->loadClientServiceIds($clientId);

        return $this->loadServicesByIds($serviceIds);
    }

    private function loadServicesByIds(array $serviceIds): Collection
    {
        if ($serviceIds === []) {
            return collect();
        }

        return Service::query()
            ->whereIn('id', $serviceIds)
            ->orderBy('name')
            ->get();
    }

    private function serializeService(Service $service): array
    {
        return [
            'id' => (int) $service->id,
            'name' => $service->name,
            'duration_minutes' => (int) $service->duration_minutes,
            'color' => $service->color,
            'active' => (bool) $service->active,
        ];
    }

    private function formatDelayMinutesAsClock(int $minutes): string
    {
        $safeMinutes = max(0, $minutes);
        $hours = (int) floor($safeMinutes / 60);
        $remainingMinutes = $safeMinutes % 60;

        return sprintf('%02d:%02d', $hours, $remainingMinutes);
    }

    private function loadAssignmentWithinQuadrant(string $quadrantId, string $assignmentId): ?ScheduleAssignment
    {
        return ScheduleAssignment::query()
            ->with('schedule')
            ->where('schedule_id', $quadrantId)
            ->find($assignmentId);
    }

    private function ensureQuadrantAccess(User $authUser, ?Schedule $quadrant, bool $write = false): ?JsonResponse
    {
        if (! $quadrant) {
            return response()->json(['success' => false, 'message' => 'Cuadrante no encontrado'], 404);
        }

        if ($authUser->role === 'coordinator' && (int) $quadrant->zone_id !== (int) $authUser->zone_id) {
            return $this->forbidden('Sin permisos');
        }

        if ($authUser->role === 'employee') {
            if ($write) {
                return $this->forbidden('Sin permisos');
            }

            $hasAssignment = ScheduleAssignment::query()
                ->where('schedule_id', $quadrant->id)
                ->where('employee_id', $authUser->id)
                ->where('is_active', true)
                ->exists();

            if (! $hasAssignment) {
                return $this->forbidden('Sin permisos');
            }
        }

        return null;
    }

    private function resolveWeekStart(Request $request): Carbon
    {
        return $request->filled('week_start')
            ? Carbon::parse($request->string('week_start')->toString())->startOfDay()
            : Carbon::now()->startOfWeek();
    }

    private function serializeAvailabilityVisit(?ScheduleAssignment $assignment): ?array
    {
        if (! $assignment) {
            return null;
        }

        return [
            'start_time' => $assignment->start_time,
            'end_time' => $assignment->end_time,
            'client_name' => $assignment->client?->name,
            'latitude' => $assignment->client?->latitude,
            'longitude' => $assignment->client?->longitude,
            'address' => $assignment->client?->address,
            'city' => $assignment->client?->city,
        ];
    }

    private function distanceForVisit(?ScheduleAssignment $assignment, ?Client $targetClient): ?float
    {
        if (! $assignment || ! $targetClient || ! $assignment->client?->latitude || ! $assignment->client?->longitude || ! $targetClient->latitude || ! $targetClient->longitude) {
            return null;
        }

        return round($this->haversineKm(
            (float) $assignment->client->latitude,
            (float) $assignment->client->longitude,
            (float) $targetClient->latitude,
            (float) $targetClient->longitude,
        ), 1);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $radius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
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