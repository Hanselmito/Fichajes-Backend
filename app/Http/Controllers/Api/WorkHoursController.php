<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarHoliday;
use App\Models\Record;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\ZoneHoliday;
use App\Support\LegacyApiAuth;
use App\Support\LegacyScheduleHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WorkHoursController extends Controller
{
    public function __construct(
        private readonly LegacyApiAuth $legacyApiAuth,
        private readonly LegacyScheduleHistory $scheduleHistory,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if ($request->has('today')) {
            return $this->today($request, $authUser);
        }

        if ($request->has('balance')) {
            return $this->balance($request, $authUser);
        }

        return response()->json(['success' => false, 'message' => 'Endpoint no encontrado'], 404);
    }

    private function today(Request $request, User $authUser): JsonResponse
    {
        $employeeId = $request->integer('employeeId', (int) $authUser->id);
        if (! $this->canAccessEmployee($authUser, $employeeId, 'can_view_all_bolsa')) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $user = User::query()->find($employeeId);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        $weeklyHours = $this->resolveWeeklyHours($user);
        $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
        $hoursWorked = $this->calculateWorkedHours($employeeId, $weekStart->toDateString(), Carbon::today()->toDateString(), true);
        $percentage = $weeklyHours > 0 ? round(($hoursWorked / $weeklyHours) * 100, 1) : 0.0;

        return response()->json([
            'success' => true,
            'hours_worked' => $hoursWorked,
            'work_hours' => $weeklyHours,
            'percentage' => min(100, $percentage),
            'remaining' => max(0, round($weeklyHours - $hoursWorked, 2)),
            'overtime' => max(0, round($hoursWorked - $weeklyHours, 2)),
            'status' => $hoursWorked >= $weeklyHours ? 'completed' : 'in_progress',
        ]);
    }

    private function balance(Request $request, User $authUser): JsonResponse
    {
        $employeeId = $request->integer('employeeId', (int) $authUser->id);
        if (! $this->canAccessEmployee($authUser, $employeeId, 'can_view_all_bolsa')) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $user = User::query()->find($employeeId);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $periodStart = Carbon::parse($request->string('start_date')->toString())->startOfDay();
            $periodEnd = Carbon::parse($request->string('end_date')->toString())->startOfDay();
            $monthLabel = $periodStart->toDateString() . ' / ' . $periodEnd->toDateString();
        } else {
            $month = $request->string('month', now()->format('Y-m'))->toString();
            $periodStart = Carbon::parse($month . '-01')->startOfDay();
            $periodEnd = $periodStart->copy()->endOfMonth()->startOfDay();
            $monthLabel = $month;
        }

        if ($user->created_at && Carbon::parse($user->created_at)->greaterThan($periodStart)) {
            $periodStart = Carbon::parse($user->created_at)->startOfDay();
        }
        if ($periodEnd->greaterThan(now())) {
            $periodEnd = now()->startOfDay();
        }

        $vacationDays = $this->approvedVacationDays($employeeId, $periodStart, $periodEnd);
        $holidayDays = $this->holidayDays($user, $periodStart, $periodEnd);
        $records = Record::query()
            ->selectRaw('DATE(timestamp) as work_date, type, timestamp')
            ->where('employee_id', $employeeId)
            ->whereBetween(DB::raw('DATE(timestamp)'), [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->orderBy('timestamp')
            ->get()
            ->groupBy('work_date');

        $daysDetail = [];
        $workDays = 0;
        $totalMinutes = 0;

        for ($cursor = $periodStart->copy(); $cursor->lessThanOrEqualTo($periodEnd); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $expected = $this->scheduleHistory->getExpectedHoursForDate($employeeId, $cursor, (float) ($user->work_hours ?? 8.0));

            if (isset($vacationDays[$date])) {
                $daysDetail[] = ['date' => $date, 'hours' => 0, 'expected' => 0, 'balance' => 0, 'has_records' => false, 'type' => 'vacation'];
                continue;
            }

            if (isset($holidayDays[$date]) && $expected > 0) {
                $daysDetail[] = ['date' => $date, 'hours' => 0, 'expected' => 0, 'balance' => 0, 'has_records' => false, 'type' => 'holiday', 'holiday_name' => $holidayDays[$date]];
                continue;
            }

            if ($expected <= 0) {
                continue;
            }

            $workDays++;
            $dayHours = $this->calculateHoursFromRecords($records->get($date)?->all() ?? [], false);
            $totalMinutes += (int) round($dayHours * 60);
            $daysDetail[] = [
                'date' => $date,
                'hours' => $dayHours,
                'expected' => $expected,
                'balance' => round($dayHours - $expected, 2),
                'has_records' => $records->has($date),
                'type' => 'work',
            ];
        }

        $anotaciones = DB::table('bolsa_anotaciones')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->orderBy('date')
            ->orderBy('created_at')
            ->get();

        $horasAjuste = 0.0;
        foreach ($anotaciones as $anotacion) {
            $adjustment = (bool) $anotacion->affects_hours ? (float) $anotacion->hours_adjustment : 0.0;
            $horasAjuste += $adjustment;
            $found = false;
            foreach ($daysDetail as &$day) {
                if ($day['date'] !== $anotacion->date) {
                    continue;
                }

                $day['anotaciones'][] = (array) $anotacion;
                $day['hours'] = round((float) $day['hours'] + $adjustment, 2);
                $day['balance'] = round((float) $day['balance'] + $adjustment, 2);
                $found = true;
                break;
            }
            unset($day);

            if (! $found) {
                $daysDetail[] = [
                    'date' => $anotacion->date,
                    'hours' => $adjustment,
                    'expected' => 0,
                    'balance' => $adjustment,
                    'has_records' => false,
                    'type' => 'annotation_only',
                    'anotaciones' => [(array) $anotacion],
                ];
            }
        }

        usort($daysDetail, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        $totalHoursWorked = round($totalMinutes / 60, 2);
        $expectedHours = round(array_reduce($daysDetail, static fn (float $sum, array $day): float => $sum + (float) $day['expected'], 0.0), 2);
        $balance = round(($totalHoursWorked + $horasAjuste) - $expectedHours, 2);

        return response()->json([
            'success' => true,
            'month' => $monthLabel,
            'work_days' => $workDays,
            'vacation_days' => count($vacationDays),
            'holiday_days' => count($holidayDays),
            'total_hours_worked' => $totalHoursWorked,
            'horas_ajuste_anotaciones' => round($horasAjuste, 2),
            'expected_hours' => $expectedHours,
            'balance' => $balance,
            'balance_status' => $balance >= 0 ? 'positive' : 'negative',
            'days_detail' => $daysDetail,
        ]);
    }

    private function canAccessEmployee(User $authUser, int $employeeId, string $permission): bool
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

        $zoneScope = $this->legacyApiAuth->getAccessibleZoneScope($authUser, $permission);

        return $zoneScope === null || in_array((int) $target->zone_id, $zoneScope, true);
    }

    private function resolveWeeklyHours(User $user): float
    {
        $snapshot = $this->scheduleHistory->getVersionForDate((int) $user->id, now()->toDateString());
        if ($snapshot && (float) $snapshot['weekly_hours'] > 0) {
            return round((float) $snapshot['weekly_hours'], 2);
        }

        return round(((float) ($user->work_hours ?? 8.0)) * 5, 2);
    }

    private function calculateWorkedHours(int $employeeId, string $startDate, string $endDate, bool $openShiftCountsUntilNow): float
    {
        $records = Record::query()
            ->select(['type', 'timestamp'])
            ->where('employee_id', $employeeId)
            ->whereBetween(DB::raw('DATE(timestamp)'), [$startDate, $endDate])
            ->orderBy('timestamp')
            ->get()
            ->all();

        return $this->calculateHoursFromRecords($records, $openShiftCountsUntilNow);
    }

    private function calculateHoursFromRecords(array $records, bool $openShiftCountsUntilNow): float
    {
        $totalMinutes = 0;
        $lastEntrada = null;

        foreach ($records as $record) {
            $type = is_array($record) ? $record['type'] : $record->type;
            $timestamp = Carbon::parse(is_array($record) ? $record['timestamp'] : $record->timestamp);

            if ($type === 'entrada') {
                $lastEntrada = $timestamp;
                continue;
            }

            if ($type === 'salida' && $lastEntrada) {
                $totalMinutes += $lastEntrada->diffInMinutes($timestamp);
                $lastEntrada = null;
            }
        }

        if ($openShiftCountsUntilNow && $lastEntrada) {
            $totalMinutes += $lastEntrada->diffInMinutes(now());
        }

        return round($totalMinutes / 60, 2);
    }

    private function approvedVacationDays(int $employeeId, Carbon $periodStart, Carbon $periodEnd): array
    {
        $requests = VacationRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'aprobada')
            ->where(function ($query) use ($periodStart, $periodEnd): void {
                $query->whereBetween('start_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                    ->orWhereBetween('end_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                    ->orWhere(function ($query) use ($periodStart, $periodEnd): void {
                        $query->where('start_date', '<=', $periodStart->toDateString())
                            ->where('end_date', '>=', $periodEnd->toDateString());
                    });
            })
            ->get();

        $days = [];
        foreach ($requests as $request) {
            for ($cursor = Carbon::parse($request->start_date); $cursor->lessThanOrEqualTo(Carbon::parse($request->end_date)); $cursor->addDay()) {
                $days[$cursor->toDateString()] = true;
            }
        }

        return $days;
    }

    private function holidayDays(User $user, Carbon $periodStart, Carbon $periodEnd): array
    {
        $holidayRows = collect();
        if ($user->calendar_id) {
            $holidayRows = $holidayRows->merge(ZoneHoliday::query()->whereNull('zone_id')->get());
            $holidayRows = $holidayRows->merge(CalendarHoliday::query()->where('calendar_id', $user->calendar_id)->get());
        } elseif ($user->zone_id) {
            $holidayRows = $holidayRows->merge(ZoneHoliday::query()->where(function ($query) use ($user): void {
                $query->where('zone_id', $user->zone_id)->orWhereNull('zone_id');
            })->get());
        }

        $days = [];
        foreach ($holidayRows as $holiday) {
            if ($holiday->recurring) {
                for ($year = (int) $periodStart->format('Y'); $year <= (int) $periodEnd->format('Y'); $year++) {
                    $date = sprintf('%d-%s', $year, Carbon::parse($holiday->date)->format('m-d'));
                    if ($date >= $periodStart->toDateString() && $date <= $periodEnd->toDateString()) {
                        $days[$date] = $holiday->name;
                    }
                }
                continue;
            }

            $date = Carbon::parse($holiday->date)->toDateString();
            if ($date >= $periodStart->toDateString() && $date <= $periodEnd->toDateString()) {
                $days[$date] = $holiday->name;
            }
        }

        return $days;
    }
}