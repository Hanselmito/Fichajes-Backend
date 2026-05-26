<?php

namespace App\Support;

use App\Models\BreakModel;
use Illuminate\Support\Carbon;

class LegacyWorkedHours
{
    public function loadBreaksByRecordId(int $employeeId, string $startDate, string $endDate): array
    {
        return $this->loadBreaksByEmployeeAndRecord([$employeeId], $startDate, $endDate)[$employeeId] ?? [];
    }

    public function loadBreaksByEmployeeAndRecord(array $employeeIds, string $startDate, string $endDate): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $breaks = BreakModel::query()
            ->select(['id', 'record_id', 'employee_id', 'break_start', 'break_end', 'break_type'])
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('break_start', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ])
            ->orderBy('break_start')
            ->get();

        $result = [];
        foreach ($breaks as $break) {
            $employeeId = (int) $break->employee_id;
            $recordId = (int) $break->record_id;
            $result[$employeeId][$recordId][] = $break;
        }

        return $result;
    }

    public function calculateHoursFromRecords(array $records, array $breaksByRecordId, bool $includeOpenShift, ?Carbon $referenceTime = null): float
    {
        $referenceTime ??= now();

        $totalMinutes = 0;
        $lastEntrada = null;
        $lastEntradaRecordId = null;

        foreach ($records as $record) {
            $type = (string) (is_array($record) ? $record['type'] : $record->type);
            $timestamp = Carbon::parse(is_array($record) ? $record['timestamp'] : $record->timestamp);
            $recordId = (int) (is_array($record) ? ($record['id'] ?? 0) : $record->id);

            if ($type === 'entrada') {
                $lastEntrada = $timestamp;
                $lastEntradaRecordId = $recordId;
                continue;
            }

            if ($type === 'salida' && $lastEntrada) {
                $shiftMinutes = $lastEntrada->diffInMinutes($timestamp);
                $breakMinutes = $this->calculateBreakMinutesForShift(
                    $breaksByRecordId[$lastEntradaRecordId] ?? [],
                    $timestamp,
                    false,
                    $referenceTime,
                );

                $totalMinutes += max(0, $shiftMinutes - $breakMinutes);
                $lastEntrada = null;
                $lastEntradaRecordId = null;
            }
        }

        if ($includeOpenShift && $lastEntrada) {
            $shiftMinutes = $lastEntrada->diffInMinutes($referenceTime);
            $breakMinutes = $this->calculateBreakMinutesForShift(
                $breaksByRecordId[$lastEntradaRecordId] ?? [],
                $referenceTime,
                true,
                $referenceTime,
            );

            $totalMinutes += max(0, $shiftMinutes - $breakMinutes);
        }

        return round($totalMinutes / 60, 2);
    }

    private function calculateBreakMinutesForShift(array $breaks, Carbon $shiftEnd, bool $includeOpenBreaks, Carbon $referenceTime): int
    {
        $totalMinutes = 0;

        foreach ($breaks as $break) {
            $start = Carbon::parse(is_array($break) ? $break['break_start'] : $break->break_start);
            $rawEnd = is_array($break) ? ($break['break_end'] ?? null) : $break->break_end;

            if ($rawEnd === null && ! $includeOpenBreaks) {
                continue;
            }

            $end = $rawEnd ? Carbon::parse($rawEnd) : $referenceTime;
            if ($end->greaterThan($shiftEnd)) {
                $end = $shiftEnd->copy();
            }

            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            $totalMinutes += $start->diffInMinutes($end);
        }

        return $totalMinutes;
    }
}