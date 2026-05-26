<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarHoliday;
use App\Models\Record;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleException;
use App\Models\User;
use App\Models\Vacation;
use App\Models\VacationRequest;
use App\Models\ZoneHoliday;
use App\Support\LegacyApiAuth;
use App\Support\LegacyScheduleHistory;
use App\Support\LegacyWorkedHours;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        private readonly LegacyApiAuth $legacyApiAuth,
        private readonly LegacyScheduleHistory $scheduleHistory,
        private readonly LegacyWorkedHours $workedHours,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $reportsScopeMode = $request->boolean('reportsScope');
        $includeCoordinators = $request->boolean('includeCoordinators');
        $query = User::query()
            ->select(['id', 'name', 'email', 'phone', 'role', 'zone_id', 'work_hours', 'active', 'schedule_start', 'schedule_end', 'schedule_start_2', 'schedule_end_2', 'calendar_id'])
            ->with('zone:id,name')
            ->orderBy('name');

        if ($authUser->role === 'coordinator') {
            if ($reportsScopeMode && $this->legacyApiAuth->userHasAccess($authUser, 'can_view_reports')) {
                $query->whereIn('role', ['employee', 'coordinator']);
                $this->applyZoneScope($query, $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_reports'));
            } elseif ($this->legacyApiAuth->userHasAccess($authUser, 'can_view_all_dashboard')) {
                $query->where('role', '!=', 'admin');
                $this->applyZoneScope($query, $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_all_dashboard'));
            } elseif ($includeCoordinators && ($this->legacyApiAuth->userHasAccess($authUser, 'can_view_coordinators_in_employee_view') || $this->legacyApiAuth->userHasAccess($authUser, 'can_view_all_dashboard'))) {
                $query->whereIn('role', ['employee', 'coordinator']);
                $scope = $this->legacyApiAuth->userHasAccess($authUser, 'can_view_coordinators_in_employee_view')
                    ? $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_coordinators_in_employee_view')
                    : $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_all_dashboard');
                $this->applyZoneScope($query, $scope);
            } else {
                $query->where('zone_id', $authUser->zone_id)->where('role', 'employee');
            }
        } else {
            $query->where('role', '!=', 'admin');
        }

        $employees = $query->get();
        $employeeIds = $employees->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);

        $activeLeaves = $this->activeLeaves($employeeIds);
        $todayClients = $this->todayClientAssignments($employeeIds);
        $weekAssignments = $this->currentWeekAssignments($employeeIds, $weekStart);
        $todayHolidayMap = $this->todayHolidayMap($employees);
        $weekBreaks = $this->workedHours->loadBreaksByEmployeeAndRecord($employeeIds, $weekStart->toDateString(), now()->toDateString());

        $lastRecords = Record::query()
            ->select(['employee_id', 'type', 'timestamp'])
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('timestamp')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');

        $weekRecords = Record::query()
            ->select(['id', 'employee_id', 'type', 'timestamp'])
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween(DB::raw('DATE(timestamp)'), [$weekStart->toDateString(), now()->toDateString()])
            ->orderBy('timestamp')
            ->get()
            ->groupBy('employee_id');

        $result = $employees->map(function (User $employee) use ($lastRecords, $activeLeaves, $todayClients, $weekAssignments, $todayHolidayMap, $weekRecords, $weekBreaks): array {
            $lastRecord = $lastRecords->get($employee->id);
            $status = 'ausente';
            $lastAction = $lastRecord?->type;
            $lastActionTime = $lastRecord?->timestamp;

            if ($lastRecord && Carbon::parse($lastRecord->timestamp)->isToday() && $lastRecord->type === 'entrada') {
                $status = 'trabajando';
            }

            $vacation = $activeLeaves[(int) $employee->id] ?? null;
            if ($vacation) {
                $status = 'vacaciones';
            }

            $hoursWorked = $this->workedHours->calculateHoursFromRecords(
                $weekRecords->get($employee->id)?->all() ?? [],
                $weekBreaks[(int) $employee->id] ?? [],
                true,
            );
            $weeklyHours = $this->resolveWeeklyHours($employee);
            $withinSchedule = $this->withinSchedule($employee) && ! ($todayHolidayMap[(int) $employee->id] ?? false);
            $currentClient = $todayClients[(int) $employee->id] ?? null;
            $nextAssignment = $this->nextAssignment($weekAssignments[(int) $employee->id] ?? [], $currentClient['client_name'] ?? null);
            $display = $this->resolveStatusDisplay($employee, $status, $vacation, $withinSchedule);

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'role' => $employee->role,
                'zone_id' => $employee->zone_id,
                'zone_name' => $employee->zone?->name,
                'active' => (int) ($employee->active ?? true),
                'work_hours' => (float) ($employee->work_hours ?? 8.0),
                'weekly_hours' => $weeklyHours,
                'schedule_start' => $employee->schedule_start,
                'schedule_end' => $employee->schedule_end,
                'schedule_start_2' => $employee->schedule_start_2,
                'schedule_end_2' => $employee->schedule_end_2,
                'within_schedule' => $withinSchedule,
                'status' => $status,
                'status_display_key' => $display['key'],
                'status_display_label' => $display['label'],
                'status_display_tone' => $display['tone'],
                'last_action' => $lastAction,
                'last_action_time' => $lastActionTime,
                'hours_worked_week' => $hoursWorked,
                'percentage' => min(100, $weeklyHours > 0 ? round(($hoursWorked / $weeklyHours) * 100, 1) : 0),
                'vacation_type' => $vacation['type'] ?? null,
                'current_client_name' => $currentClient['client_name'] ?? null,
                'current_client_time' => $currentClient['timestamp'] ?? null,
                'next_assignment' => $nextAssignment,
            ];
        })->values();

        $checkinsSummary = $this->checkinsSummary($authUser, $reportsScopeMode);

        return response()->json([
            'success' => true,
            'employees' => $result,
            'total' => $result->count(),
            'trabajando' => $result->where('status', 'trabajando')->count(),
            'ausente' => $result->where('status', 'ausente')->count(),
            'vacaciones' => $result->where('status', 'vacaciones')->count(),
            'checkins_summary' => $checkinsSummary,
        ]);
    }

    private function applyZoneScope(Builder $query, ?array $zoneScope): void
    {
        if ($zoneScope === null) {
            return;
        }

        if ($zoneScope === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn('zone_id', $zoneScope);
    }

    private function resolveWeeklyHours(User $employee): float
    {
        $snapshot = $this->scheduleHistory->getVersionForDate((int) $employee->id, now()->toDateString());

        return $snapshot ? round((float) $snapshot['weekly_hours'], 2) : round(((float) ($employee->work_hours ?? 8.0)) * 5, 2);
    }

    private function withinSchedule(User $employee): bool
    {
        $nowTime = now()->format('H:i:s');
        $primary = $employee->schedule_start && $employee->schedule_end && $nowTime >= $employee->schedule_start && $nowTime <= $employee->schedule_end;
        $secondary = $employee->schedule_start_2 && $employee->schedule_end_2 && $nowTime >= $employee->schedule_start_2 && $nowTime <= $employee->schedule_end_2;

        return $primary || $secondary;
    }

    private function activeLeaves(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $result = [];
        $today = now()->toDateString();

        foreach (Vacation::query()->whereIn('employee_id', $employeeIds)->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->orderByDesc('start_date')->get() as $leave) {
            $result[(int) $leave->employee_id] ??= [
                'source' => 'vacations',
                'type' => $leave->type,
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
            ];
        }

        foreach (VacationRequest::query()->whereIn('employee_id', $employeeIds)->where('status', 'aprobada')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->orderByDesc('start_date')->get() as $leave) {
            $result[(int) $leave->employee_id] ??= [
                'source' => 'vacation_requests',
                'type' => $leave->type,
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
            ];
        }

        return $result;
    }

    private function todayClientAssignments(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $latestTimestamps = Record::query()
            ->selectRaw('employee_id, MAX(timestamp) as max_timestamp')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('timestamp', now()->toDateString())
            ->whereNotNull('client_id')
            ->groupBy('employee_id')
            ->get();

        $result = [];
        foreach ($latestTimestamps as $row) {
            $record = Record::query()->with('client:id,name')->where('employee_id', $row->employee_id)->where('timestamp', $row->max_timestamp)->first();
            if ($record) {
                $result[(int) $record->employee_id] = [
                    'client_id' => $record->client_id ? (int) $record->client_id : null,
                    'client_name' => $record->client?->name,
                    'timestamp' => $record->timestamp,
                ];
            }
        }

        return $result;
    }

    private function currentWeekAssignments(array $employeeIds, Carbon $weekStart): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $assignments = ScheduleAssignment::query()
            ->with(['schedule:id,week_start', 'client:id,name'])
            ->whereIn('employee_id', $employeeIds)
            ->where('is_active', true)
            ->whereHas('schedule', static function (Builder $query) use ($weekStart): void {
                $query->whereDate('week_start', '<=', $weekStart->toDateString());
            })
            ->orderBy('employee_id')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $exceptions = ScheduleException::query()
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->whereBetween('exception_date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (ScheduleException $exception): string => $exception->assignment_id . '|' . $exception->exception_date)
            ->map(fn (Collection $group): ScheduleException => $group->first())
            ->all();

        $result = [];
        foreach ($assignments as $assignment) {
            $assignmentDate = $weekStart->copy()->addDays(((int) $assignment->day_of_week) - 1)->toDateString();
            $exception = $exceptions[$assignment->id . '|' . $assignmentDate] ?? null;
            if ($exception?->type === 'cancelled') {
                continue;
            }

            $startTime = $exception?->type === 'modified' && $exception->new_start_time ? $exception->new_start_time : $assignment->start_time;
            $endTime = $exception?->type === 'modified' && $exception->new_end_time ? $exception->new_end_time : $assignment->end_time;

            $result[(int) $assignment->employee_id][] = [
                'id' => (int) $assignment->id,
                'client_id' => $assignment->client_id ? (int) $assignment->client_id : null,
                'client_name' => $assignment->client?->name,
                'date' => $assignmentDate,
                'day_of_week' => (int) $assignment->day_of_week,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ];
        }

        return $result;
    }

    private function todayHolidayMap(Collection $employees): array
    {
        $today = now()->toDateString();
        $todayMonthDay = now()->format('m-d');
        $nationalHoliday = ZoneHoliday::query()->whereNull('zone_id')->where(function ($query) use ($today, $todayMonthDay): void {
            $query->where(function ($query) use ($todayMonthDay): void {
                $query->where('recurring', true)->whereRaw("DATE_FORMAT(date, '%m-%d') = ?", [$todayMonthDay]);
            })->orWhere(function ($query) use ($today): void {
                $query->where('recurring', false)->whereDate('date', $today);
            });
        })->exists();

        $zoneIds = $employees->pluck('zone_id')->filter()->unique()->all();
        $calendarIds = $employees->pluck('calendar_id')->filter()->unique()->all();
        $zoneHolidayMap = ZoneHoliday::query()->whereIn('zone_id', $zoneIds)->where(function ($query) use ($today, $todayMonthDay): void {
            $query->where(function ($query) use ($todayMonthDay): void {
                $query->where('recurring', true)->whereRaw("DATE_FORMAT(date, '%m-%d') = ?", [$todayMonthDay]);
            })->orWhere(function ($query) use ($today): void {
                $query->where('recurring', false)->whereDate('date', $today);
            });
        })->pluck('zone_id')->flip()->all();
        $calendarHolidayMap = CalendarHoliday::query()->whereIn('calendar_id', $calendarIds)->where(function ($query) use ($today, $todayMonthDay): void {
            $query->where(function ($query) use ($todayMonthDay): void {
                $query->where('recurring', true)->whereRaw("DATE_FORMAT(date, '%m-%d') = ?", [$todayMonthDay]);
            })->orWhere(function ($query) use ($today): void {
                $query->where('recurring', false)->whereDate('date', $today);
            });
        })->pluck('calendar_id')->flip()->all();

        $result = [];
        foreach ($employees as $employee) {
            $result[(int) $employee->id] = $nationalHoliday
                || ($employee->calendar_id && isset($calendarHolidayMap[(int) $employee->calendar_id]))
                || (! $employee->calendar_id && $employee->zone_id && isset($zoneHolidayMap[(int) $employee->zone_id]));
        }

        return $result;
    }

    private function resolveStatusDisplay(User $employee, string $status, ?array $leave, bool $withinSchedule): array
    {
        if (! $employee->active) {
            return ['key' => 'inactive', 'label' => 'Inactivo', 'tone' => 'muted'];
        }

        if ($leave) {
            if ($leave['source'] === 'vacations' && $leave['type'] === 'sick_leave') {
                return ['key' => 'sick_leave', 'label' => 'Baja medica', 'tone' => 'danger'];
            }
            if (($leave['type'] ?? null) === 'asuntos_propios' || ($leave['source'] === 'vacations' && ($leave['type'] ?? null) === 'permission')) {
                return ['key' => 'asuntos_propios', 'label' => 'Asuntos propios', 'tone' => 'warning'];
            }

            return ['key' => 'vacaciones', 'label' => 'Vacaciones', 'tone' => 'info'];
        }

        if ($status === 'trabajando') {
            return ['key' => 'active', 'label' => 'Activo', 'tone' => 'success'];
        }

        if ($withinSchedule) {
            return ['key' => 'pending', 'label' => 'Pendiente de fichar', 'tone' => 'warning'];
        }

        return ['key' => 'away', 'label' => 'Sin actividad', 'tone' => 'muted'];
    }

    private function nextAssignment(array $assignments, ?string $currentClientName): ?array
    {
        $normalizedCurrent = $currentClientName ? mb_strtolower(trim($currentClientName), 'UTF-8') : null;
        foreach ($assignments as $assignment) {
            $dateTime = Carbon::parse($assignment['date'] . ' ' . $assignment['start_time']);
            if ($dateTime->lessThanOrEqualTo(now())) {
                continue;
            }

            $normalizedAssignment = ! empty($assignment['client_name']) ? mb_strtolower(trim((string) $assignment['client_name']), 'UTF-8') : null;

            return [
                'date' => $assignment['date'],
                'start_time' => $assignment['start_time'],
                'end_time' => $assignment['end_time'],
                'client_id' => $assignment['client_id'],
                'client_name' => $assignment['client_name'],
                'same_client' => $normalizedCurrent !== null && $normalizedAssignment !== null && $normalizedCurrent === $normalizedAssignment,
            ];
        }

        return null;
    }

    private function checkinsSummary(User $authUser, bool $reportsScopeMode): array
    {
        $days = collect(range(6, 0))->mapWithKeys(static fn (int $offset): array => [
            now()->copy()->subDays($offset)->toDateString() => [
                'date' => now()->copy()->subDays($offset)->toDateString(),
                'label' => now()->copy()->subDays($offset)->format('D'),
                'total' => 0,
                'pending' => 0,
            ],
        ])->all();

        $query = Record::query()
            ->selectRaw('DATE(records.timestamp) as checkin_date, COUNT(*) as total, SUM(CASE WHEN COALESCE(records.confirmed, 0) = 0 THEN 1 ELSE 0 END) as pending')
            ->join('users', 'users.id', '=', 'records.employee_id')
            ->whereBetween(DB::raw('DATE(records.timestamp)'), [now()->copy()->subDays(6)->toDateString(), now()->toDateString()]);

        if ($authUser->role === 'coordinator') {
            $permission = $reportsScopeMode ? 'can_view_reports' : 'can_view_all_dashboard';
            $zoneScope = $this->legacyApiAuth->getAccessibleZoneScope($authUser, $permission);
            if ($zoneScope === []) {
                $query->whereRaw('1 = 0');
            } elseif ($zoneScope !== null) {
                $query->whereIn('users.zone_id', $zoneScope);
            }
        }

        $rows = $query->groupBy(DB::raw('DATE(records.timestamp)'))->orderBy('checkin_date')->get();
        foreach ($rows as $row) {
            if (isset($days[$row->checkin_date])) {
                $days[$row->checkin_date]['total'] = (int) $row->total;
                $days[$row->checkin_date]['pending'] = (int) $row->pending;
            }
        }

        $todayKey = now()->toDateString();
        $today = $days[$todayKey];

        return [
            'today' => ['date' => $today['date'], 'total' => $today['total'], 'pending' => $today['pending']],
            'history' => array_values($days),
        ];
    }
}