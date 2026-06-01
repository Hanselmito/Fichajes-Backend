<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Record;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function __construct(private readonly LegacyApiAuth $legacyApiAuth)
    {
    }

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $mode = $request->route('mode') ?: $request->string('format', 'stats')->toString();

        return match ($mode) {
            'json' => $this->jsonReport(),
            'csv' => $this->csvReport(),
            'text' => $this->textReport(),
            default => $this->statsReport(),
        };
    }

    private function statsReport(): JsonResponse
    {
        $totalRecords = Record::query()->count();
        $pendingConfirmation = Record::query()->where('confirmed', false)->count();
        $activeEmployees = \App\Models\User::query()->where('active', true)->where('role', 'employee')->count();
        $byType = Record::query()->selectRaw('type, COUNT(*) as count')->groupBy('type')->get();

        return response()->json([
            'success' => true,
            'stats' => [
                'total_records' => $totalRecords,
                'pending_confirmation' => $pendingConfirmation,
                'active_employees' => $activeEmployees,
                'by_type' => $byType,
            ],
        ]);
    }

    private function jsonReport(): JsonResponse
    {
        $records = Record::query()
            ->with(['employee:id,name', 'client:id,name'])
            ->orderByDesc('timestamp')
            ->get()
            ->map(fn (Record $record): array => array_merge($record->toArray(), [
                'employee_name' => $record->employee?->name,
                'client_name' => $record->client?->name,
            ]))
            ->values();

        return response()->json([
            'success' => true,
            'report' => [
                'generated_at' => now()->format('Y-m-d H:i:s'),
                'total_records' => $records->count(),
                'records' => $records,
            ],
        ]);
    }

    private function csvReport(): StreamedResponse
    {
        $records = Record::query()->with(['employee:id,name', 'client:id,name'])->orderByDesc('timestamp')->get();

        return response()->streamDownload(function () use ($records): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['ID', 'Fecha', 'Hora', 'Tipo', 'Empleado', 'Cliente', 'Teletrabajo', 'Confirmado']);
            foreach ($records as $record) {
                fputcsv($output, [
                    $record->id,
                    $record->timestamp?->format('Y-m-d'),
                    $record->timestamp?->format('H:i:s'),
                    $record->type,
                    $record->employee?->name,
                    $record->client?->name ?: 'N/A',
                    $record->is_teletrabajo ? 'Sí' : 'No',
                    $record->confirmed ? 'Sí' : 'No',
                ]);
            }
            fclose($output);
        }, 'fichajes_' . now()->toDateString() . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function textReport(): StreamedResponse
    {
        $records = Record::query()->with(['employee:id,name', 'client:id,name'])->orderByDesc('timestamp')->get();

        return response()->streamDownload(function () use ($records): void {
            echo "REPORTE DE FICHAJES\n";
            echo 'Generado: ' . now()->format('Y-m-d H:i:s') . "\n";
            echo str_repeat('=', 80) . "\n\n";

            foreach ($records as $record) {
                echo sprintf(
                    "[%s] %s - %s - %s\n",
                    $record->timestamp?->format('Y-m-d H:i:s'),
                    strtoupper((string) $record->type),
                    $record->employee?->name,
                    $record->client?->name ?: 'Teletrabajo',
                );
            }
        }, 'fichajes_' . now()->toDateString() . '.txt', ['Content-Type' => 'text/plain']);
    }
}
