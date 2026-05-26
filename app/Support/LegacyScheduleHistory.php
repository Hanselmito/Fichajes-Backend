<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LegacyScheduleHistory
{
    public function ensureTables(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS employee_schedule_segments (
                id INT NOT NULL,
                schedule_id INT NOT NULL,
                segment_index TINYINT NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_schedule_segment (schedule_id, segment_index),
                KEY idx_schedule_segment_schedule (schedule_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS employee_schedule_history_versions (
                id INT NOT NULL,
                employee_id INT NOT NULL,
                effective_date DATE NOT NULL,
                weekly_hours DECIMAL(5,2) DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_employee_effective_date (employee_id, effective_date),
                KEY idx_employee_effective_date (employee_id, effective_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS employee_schedule_history_days (
                id INT NOT NULL,
                version_id INT NOT NULL,
                day_of_week TINYINT NOT NULL,
                is_working_day TINYINT(1) NOT NULL DEFAULT 0,
                start_time TIME DEFAULT NULL,
                end_time TIME DEFAULT NULL,
                shift_start_2 TIME DEFAULT NULL,
                shift_end_2 TIME DEFAULT NULL,
                daily_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_version_day_of_week (version_id, day_of_week),
                KEY idx_history_days_version (version_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        DB::statement(
            'CREATE TABLE IF NOT EXISTS employee_schedule_history_segments (
                id INT NOT NULL,
                history_day_id INT NOT NULL,
                segment_index TINYINT NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_history_day_segment (history_day_id, segment_index),
                KEY idx_history_segments_day (history_day_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function listVersions(int $employeeId): array
    {
        $this->ensureInitialVersion($employeeId);

        return DB::table('employee_schedule_history_versions')
            ->where('employee_id', $employeeId)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($version): array => [
                'id' => (int) $version->id,
                'employee_id' => (int) $version->employee_id,
                'effective_date' => $version->effective_date,
                'weekly_hours' => $version->weekly_hours !== null ? round((float) $version->weekly_hours, 2) : null,
                'created_by' => $version->created_by !== null ? (int) $version->created_by : null,
                'created_at' => $version->created_at,
            ])
            ->all();
    }

    public function getVersionForDate(int $employeeId, string $date): ?array
    {
        $this->ensureInitialVersion($employeeId);

        $version = DB::table('employee_schedule_history_versions')
            ->where('employee_id', $employeeId)
            ->whereDate('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        if (! $version) {
            return null;
        }

        $days = DB::table('employee_schedule_history_days')
            ->where('version_id', $version->id)
            ->orderBy('day_of_week')
            ->get();

        $dayIds = $days->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $segmentsByDayId = [];

        if ($dayIds !== []) {
            $segments = DB::table('employee_schedule_history_segments')
                ->whereIn('history_day_id', $dayIds)
                ->orderBy('history_day_id')
                ->orderBy('segment_index')
                ->get();

            foreach ($segments as $segment) {
                $segmentsByDayId[(int) $segment->history_day_id][] = [
                    'start_time' => $this->normalizeTime($segment->start_time),
                    'end_time' => $this->normalizeTime($segment->end_time),
                ];
            }
        }

        $daysByNumber = [];
        foreach ($days as $day) {
            $dayNumber = (int) $day->day_of_week;
            $segments = $segmentsByDayId[(int) $day->id] ?? $this->buildSegmentsFromRow((array) $day);

            $daysByNumber[$dayNumber] = [
                'day_of_week' => $dayNumber,
                'is_working_day' => (int) $day->is_working_day,
                'segments' => $segments,
                'daily_hours' => round((float) $day->daily_hours, 2),
            ];
        }

        for ($day = 0; $day <= 6; $day++) {
            if (! isset($daysByNumber[$day])) {
                $daysByNumber[$day] = [
                    'day_of_week' => $day,
                    'is_working_day' => 0,
                    'segments' => [],
                    'daily_hours' => 0.0,
                ];
            }
        }

        ksort($daysByNumber);

        $weeklyHours = $version->weekly_hours !== null
            ? round((float) $version->weekly_hours, 2)
            : round(array_sum(array_map(static fn (array $day): float => (float) $day['daily_hours'], $daysByNumber)), 2);

        return [
            'id' => (int) $version->id,
            'employee_id' => (int) $version->employee_id,
            'effective_date' => $version->effective_date,
            'weekly_hours' => $weeklyHours,
            'days' => $daysByNumber,
        ];
    }

    public function getExpectedHoursForDate(int $employeeId, DateTimeInterface|string $date, float $fallbackHoursPerDay = 8.0): float
    {
        $dateValue = $date instanceof DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);
        $snapshot = $this->getVersionForDate($employeeId, $dateValue->toDateString());
        $dayOfWeek = (int) $dateValue->format('w');

        if (! $snapshot || ! isset($snapshot['days'][$dayOfWeek])) {
            return $dayOfWeek >= 1 && $dayOfWeek <= 5 ? round($fallbackHoursPerDay, 2) : 0.0;
        }

        $day = $snapshot['days'][$dayOfWeek];
        if ((int) $day['is_working_day'] !== 1) {
            return 0.0;
        }

        $plannedWeeklyHours = round(array_reduce($snapshot['days'], static function (float $sum, array $snapshotDay): float {
            return (int) $snapshotDay['is_working_day'] === 1 ? $sum + (float) $snapshotDay['daily_hours'] : $sum;
        }, 0.0), 2);

        if ($plannedWeeklyHours > 0 && (float) $snapshot['weekly_hours'] > 0) {
            return round(((float) $day['daily_hours'] / $plannedWeeklyHours) * (float) $snapshot['weekly_hours'], 2);
        }

        return round((float) $day['daily_hours'], 2);
    }

    public function saveVersion(int $employeeId, array $normalizedSchedules, float $weeklyHours, string $effectiveDate, ?int $createdBy = null): int
    {
        $this->ensureTables();

        $existingVersionId = DB::table('employee_schedule_history_versions')
            ->where('employee_id', $employeeId)
            ->whereDate('effective_date', $effectiveDate)
            ->value('id');

        $versionId = $existingVersionId ? (int) $existingVersionId : $this->nextLegacyId('employee_schedule_history_versions');

        DB::table('employee_schedule_history_versions')->updateOrInsert(
            ['id' => $versionId],
            [
                'employee_id' => $employeeId,
                'effective_date' => $effectiveDate,
                'weekly_hours' => round($weeklyHours, 2),
                'created_by' => $createdBy,
            ]
        );

        $dayIds = DB::table('employee_schedule_history_days')->where('version_id', $versionId)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($dayIds !== []) {
            DB::table('employee_schedule_history_segments')->whereIn('history_day_id', $dayIds)->delete();
        }
        DB::table('employee_schedule_history_days')->where('version_id', $versionId)->delete();

        foreach ($normalizedSchedules as $schedule) {
            $segments = $schedule['segments'];
            $primary = $segments[0] ?? ['start_time' => null, 'end_time' => null];
            $secondary = $segments[1] ?? ['start_time' => null, 'end_time' => null];

            $historyDayId = $this->nextLegacyId('employee_schedule_history_days');
            DB::table('employee_schedule_history_days')->insert([
                'id' => $historyDayId,
                'version_id' => $versionId,
                'day_of_week' => $schedule['day_of_week'],
                'is_working_day' => $schedule['is_working_day'],
                'start_time' => $schedule['is_working_day'] ? ($primary['start_time'] ?? null) : null,
                'end_time' => $schedule['is_working_day'] ? ($primary['end_time'] ?? null) : null,
                'shift_start_2' => $schedule['is_working_day'] ? ($secondary['start_time'] ?? null) : null,
                'shift_end_2' => $schedule['is_working_day'] ? ($secondary['end_time'] ?? null) : null,
                'daily_hours' => round((float) $schedule['daily_hours'], 2),
            ]);

            foreach ($segments as $index => $segment) {
                DB::table('employee_schedule_history_segments')->insert([
                    'id' => $this->nextLegacyId('employee_schedule_history_segments'),
                    'history_day_id' => $historyDayId,
                    'segment_index' => $index + 1,
                    'start_time' => $segment['start_time'],
                    'end_time' => $segment['end_time'],
                ]);
            }
        }

        return $versionId;
    }

    public function syncCurrentTables(int $employeeId, string $referenceDate): void
    {
        $snapshot = $this->getVersionForDate($employeeId, $referenceDate);
        if (! $snapshot) {
            return;
        }

        DB::table('users')->where('id', $employeeId)->update([
            'weekly_hours' => round((float) $snapshot['weekly_hours'], 2),
        ]);

        foreach ($snapshot['days'] as $dayNumber => $day) {
            $segments = $day['segments'];
            $primary = $segments[0] ?? ['start_time' => null, 'end_time' => null];
            $secondary = $segments[1] ?? ['start_time' => null, 'end_time' => null];
            $isWorkday = (int) $day['is_working_day'] === 1;

            $scheduleId = DB::table('employee_schedules')
                ->where('employee_id', $employeeId)
                ->where('day_of_week', $dayNumber)
                ->value('id');

            $payload = [
                'employee_id' => $employeeId,
                'day_of_week' => $dayNumber,
                'is_working_day' => $isWorkday ? 1 : 0,
                'start_time' => $isWorkday ? ($primary['start_time'] ?? null) : null,
                'end_time' => $isWorkday ? ($primary['end_time'] ?? null) : null,
                'shift_start_2' => $isWorkday ? ($secondary['start_time'] ?? null) : null,
                'shift_end_2' => $isWorkday ? ($secondary['end_time'] ?? null) : null,
                'daily_hours' => round((float) $day['daily_hours'], 2),
            ];

            if ($scheduleId) {
                DB::table('employee_schedules')->where('id', $scheduleId)->update($payload);
                $resolvedScheduleId = (int) $scheduleId;
            } else {
                $resolvedScheduleId = $this->nextLegacyId('employee_schedules');
                DB::table('employee_schedules')->insert(['id' => $resolvedScheduleId] + $payload + [
                    'entry_tolerance_minutes' => 15,
                    'exit_tolerance_minutes' => 15,
                ]);
            }

            DB::table('employee_schedule_segments')->where('schedule_id', $resolvedScheduleId)->delete();
            foreach (array_slice($segments, 2) as $index => $segment) {
                DB::table('employee_schedule_segments')->insert([
                    'id' => $this->nextLegacyId('employee_schedule_segments'),
                    'schedule_id' => $resolvedScheduleId,
                    'segment_index' => $index + 3,
                    'start_time' => $segment['start_time'],
                    'end_time' => $segment['end_time'],
                ]);
            }
        }
    }

    public function normalizeSchedules(array $schedules): array
    {
        $normalized = [];

        foreach ($schedules as $schedule) {
            $dayNumber = $this->resolveDayNumber($schedule['day_of_week'] ?? $schedule['dayOfWeek'] ?? null);
            if ($dayNumber === null) {
                continue;
            }

            $segments = [];
            if (! empty($schedule['segments']) && is_array($schedule['segments'])) {
                foreach ($schedule['segments'] as $segment) {
                    $start = $this->normalizeTime($segment['start_time'] ?? $segment['startTime'] ?? null);
                    $end = $this->normalizeTime($segment['end_time'] ?? $segment['endTime'] ?? null);
                    if ($start && $end) {
                        $segments[] = ['start_time' => $start, 'end_time' => $end];
                    }
                }
            } else {
                $primaryStart = $this->normalizeTime($schedule['shift_start_1'] ?? $schedule['start_time'] ?? $schedule['startTime'] ?? null);
                $primaryEnd = $this->normalizeTime($schedule['shift_end_1'] ?? $schedule['end_time'] ?? $schedule['endTime'] ?? null);
                $secondaryStart = $this->normalizeTime($schedule['shift_start_2'] ?? null);
                $secondaryEnd = $this->normalizeTime($schedule['shift_end_2'] ?? null);

                if ($primaryStart && $primaryEnd) {
                    $segments[] = ['start_time' => $primaryStart, 'end_time' => $primaryEnd];
                }
                if ($secondaryStart && $secondaryEnd) {
                    $segments[] = ['start_time' => $secondaryStart, 'end_time' => $secondaryEnd];
                }
            }

            $isWorkingDay = (bool) ($schedule['is_workday'] ?? $schedule['is_working_day'] ?? $schedule['isWorkingDay'] ?? ! empty($segments));
            $normalized[$dayNumber] = [
                'day_of_week' => $dayNumber,
                'is_working_day' => $isWorkingDay ? 1 : 0,
                'segments' => $segments,
                'daily_hours' => isset($schedule['daily_hours'])
                    ? round((float) $schedule['daily_hours'], 2)
                    : $this->calculateDailyHours($segments, $isWorkingDay),
            ];
        }

        for ($day = 0; $day <= 6; $day++) {
            if (! isset($normalized[$day])) {
                $normalized[$day] = [
                    'day_of_week' => $day,
                    'is_working_day' => 0,
                    'segments' => [],
                    'daily_hours' => 0.0,
                ];
            }
        }

        ksort($normalized);

        return array_values($normalized);
    }

    public function ensureInitialVersion(int $employeeId, ?int $createdBy = null): void
    {
        $this->ensureTables();

        if (DB::table('employee_schedule_history_versions')->where('employee_id', $employeeId)->exists()) {
            return;
        }

        $snapshot = $this->loadCurrentSnapshot($employeeId);
        $this->saveVersion(
            $employeeId,
            $this->normalizeSchedules($snapshot['days']),
            (float) $snapshot['weekly_hours'],
            $snapshot['effective_date'],
            $createdBy,
        );
    }

    public function loadCurrentSnapshot(int $employeeId): array
    {
        $this->ensureTables();

        $user = DB::table('users')
            ->select(['id', 'created_at', 'contract_start', 'weekly_hours', 'schedule_start', 'schedule_end', 'schedule_start_2', 'schedule_end_2'])
            ->where('id', $employeeId)
            ->first();

        if (! $user) {
            throw new RuntimeException('Usuario no encontrado');
        }

        $defaultSegments = [];
        $defaultStart1 = $this->normalizeTime($user->schedule_start ?: '09:00:00') ?: '09:00:00';
        $defaultEnd1 = $this->normalizeTime($user->schedule_end ?: '18:00:00') ?: '18:00:00';
        $defaultSegments[] = ['start_time' => $defaultStart1, 'end_time' => $defaultEnd1];

        $defaultStart2 = $this->normalizeTime($user->schedule_start_2);
        $defaultEnd2 = $this->normalizeTime($user->schedule_end_2);
        if ($defaultStart2 && $defaultEnd2) {
            $defaultSegments[] = ['start_time' => $defaultStart2, 'end_time' => $defaultEnd2];
        }

        $defaultHours = $this->calculateDailyHours($defaultSegments, true);
        if ($defaultHours <= 0) {
            $defaultHours = 8.0;
        }

        $rows = DB::table('employee_schedules')
            ->select(['id', 'day_of_week', 'is_working_day', 'start_time', 'end_time', 'shift_start_2', 'shift_end_2', 'daily_hours'])
            ->where('employee_id', $employeeId)
            ->orderBy('day_of_week')
            ->get();

        $extraSegments = DB::table('employee_schedule_segments')
            ->whereIn('schedule_id', $rows->pluck('id')->all())
            ->orderBy('schedule_id')
            ->orderBy('segment_index')
            ->get()
            ->groupBy('schedule_id')
            ->map(static fn ($group): array => $group->map(static fn ($segment): array => [
                'start_time' => $segment->start_time,
                'end_time' => $segment->end_time,
            ])->all())
            ->all();

        $rowsByDay = [];
        foreach ($rows as $row) {
            $rowsByDay[(int) $row->day_of_week] = $row;
        }

        $snapshotDays = [];
        for ($day = 0; $day <= 6; $day++) {
            if (isset($rowsByDay[$day])) {
                $row = $rowsByDay[$day];
                $segments = $this->buildSegmentsFromRow((array) $row, $extraSegments[(int) $row->id] ?? []);
                $isWorkday = (int) $row->is_working_day === 1;
                $dailyHours = $row->daily_hours !== null ? round((float) $row->daily_hours, 2) : $this->calculateDailyHours($segments, $isWorkday);
            } else {
                $isWorkday = $day >= 1 && $day <= 5;
                $segments = $isWorkday ? $defaultSegments : [];
                $dailyHours = $isWorkday ? $defaultHours : 0.0;
            }

            $snapshotDays[$day] = [
                'day_of_week' => $day,
                'is_working_day' => $isWorkday ? 1 : 0,
                'segments' => $segments,
                'daily_hours' => round($dailyHours, 2),
            ];
        }

        $weeklyHours = $user->weekly_hours !== null
            ? round((float) $user->weekly_hours, 2)
            : round(array_sum(array_map(static fn (array $day): float => (float) $day['daily_hours'], $snapshotDays)), 2);

        $effectiveDate = $user->contract_start ?: Carbon::parse($user->created_at)->toDateString();

        return [
            'effective_date' => $effectiveDate,
            'weekly_hours' => $weeklyHours,
            'days' => $snapshotDays,
        ];
    }

    private function resolveDayNumber(mixed $value): ?int
    {
        if (is_int($value) || ctype_digit((string) $value)) {
            $day = (int) $value;
            if ($day >= 0 && $day <= 6) {
                return $day;
            }

            if ($day >= 1 && $day <= 7) {
                return $day === 7 ? 0 : $day;
            }
        }

        if (! is_string($value)) {
            return null;
        }

        return match (mb_strtolower(trim($value), 'UTF-8')) {
            'domingo' => 0,
            'lunes' => 1,
            'martes' => 2,
            'miercoles', 'miércoles' => 3,
            'jueves' => 4,
            'viernes' => 5,
            'sabado', 'sábado' => 6,
            default => null,
        };
    }

    private function normalizeTime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        if (preg_match('/^\d{2}:\d{2}$/', $trimmed) === 1) {
            return $trimmed . ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $trimmed) === 1) {
            return $trimmed;
        }

        return null;
    }

    private function buildSegmentsFromRow(array $row, array $extraSegments = []): array
    {
        $segments = [];
        $primaryStart = $this->normalizeTime($row['start_time'] ?? null);
        $primaryEnd = $this->normalizeTime($row['end_time'] ?? null);
        if ($primaryStart && $primaryEnd) {
            $segments[] = ['start_time' => $primaryStart, 'end_time' => $primaryEnd];
        }

        $secondaryStart = $this->normalizeTime($row['shift_start_2'] ?? null);
        $secondaryEnd = $this->normalizeTime($row['shift_end_2'] ?? null);
        if ($secondaryStart && $secondaryEnd) {
            $segments[] = ['start_time' => $secondaryStart, 'end_time' => $secondaryEnd];
        }

        foreach ($extraSegments as $segment) {
            $start = $this->normalizeTime($segment['start_time'] ?? null);
            $end = $this->normalizeTime($segment['end_time'] ?? null);
            if ($start && $end) {
                $segments[] = ['start_time' => $start, 'end_time' => $end];
            }
        }

        return $segments;
    }

    private function calculateDailyHours(array $segments, bool $isWorkday): float
    {
        if (! $isWorkday) {
            return 0.0;
        }

        $totalMinutes = 0;
        foreach ($segments as $segment) {
            $start = $this->normalizeTime($segment['start_time'] ?? null);
            $end = $this->normalizeTime($segment['end_time'] ?? null);
            if (! $start || ! $end) {
                continue;
            }

            $startTime = Carbon::createFromFormat('H:i:s', $start);
            $endTime = Carbon::createFromFormat('H:i:s', $end);
            if ($endTime->lessThanOrEqualTo($startTime)) {
                continue;
            }

            $totalMinutes += $startTime->diffInMinutes($endTime);
        }

        return round($totalMinutes / 60, 2);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}