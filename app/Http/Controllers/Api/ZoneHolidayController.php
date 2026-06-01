<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ZoneHoliday;
use App\Support\LegacyApiAuth;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ZoneHolidayController extends Controller
{
    private const NON_RECURRENT_HOLIDAYS = [
        'Jueves Santo', 'Maundy Thursday', 'Viernes Santo', 'Good Friday',
        'Lunes de Pascua', 'Easter Monday', 'Miercoles de Ceniza', 'Miércoles de Ceniza', 'Ash Wednesday',
    ];

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

        $zoneId = $request->query('zoneId');
        $year = $request->integer('year', (int) now()->format('Y'));

        $holidays = DB::table('zone_holidays as zh')
            ->select(['zh.*', 'u.name as created_by_name'])
            ->leftJoin('users as u', 'zh.created_by', '=', 'u.id')
            ->where(function ($query) use ($zoneId): void {
                if ($zoneId !== null && $zoneId !== '') {
                    $query->where('zh.zone_id', (int) $zoneId)->orWhereNull('zh.zone_id');
                    return;
                }

                $query->whereNull('zh.zone_id');
            })
            ->get()
            ->map(fn ($holiday) => $this->adjustHolidayForYear($holiday, $year))
            ->filter()
            ->sortBy('date')
            ->values();

        return response()->json(['success' => true, 'holidays' => $holidays]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        if ($request->boolean('importNager')) {
            if ($authUser->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Solo el admin puede importar'], 403);
            }

            $response = $this->fetchNagerHolidays($request->integer('year', (int) now()->format('Y')));
            if (! $response->successful()) {
                return response()->json(['success' => false, 'message' => 'No se pudo conectar con Nager.Date'], 500);
            }

            [$imported, $skipped] = $this->importHolidaysFromNager($response->json(), (int) $authUser->id);

            return response()->json([
                'success' => true,
                'message' => 'Importados: ' . $imported . ', ya existían: ' . $skipped,
                'imported' => $imported,
                'skipped' => $skipped,
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'type' => ['nullable', 'in:national,regional,local'],
            'recurring' => ['nullable', 'boolean'],
            'zoneId' => ['nullable', 'integer'],
        ]);

        $zoneId = $authUser->role === 'coordinator' ? (int) $authUser->zone_id : ($request->filled('zoneId') ? (int) $data['zoneId'] : null);
        $holidayId = $this->nextLegacyId('zone_holidays');

        ZoneHoliday::query()->create([
            'id' => $holidayId,
            'zone_id' => $zoneId,
            'name' => $data['name'],
            'date' => $data['date'],
            'type' => $data['type'] ?? 'local',
            'recurring' => array_key_exists('recurring', $data) ? (bool) $data['recurring'] : true,
            'created_by' => $authUser->id,
        ]);

        return response()->json(['success' => true, 'message' => 'Festivo creado correctamente', 'id' => $holidayId], 201);
    }

    public function destroy(Request $request, string $zoneHoliday = ''): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $holidayId = (int) ($zoneHoliday !== '' ? $zoneHoliday : $request->query('id', $request->input('id', 0)));
        if ($holidayId <= 0) {
            return response()->json(['success' => false, 'message' => 'ID requerido'], 400);
        }

        $query = DB::table('zone_holidays')->where('id', $holidayId);
        if ($authUser->role === 'coordinator') {
            $query->where('zone_id', $authUser->zone_id);
        }
        $query->delete();

        return response()->json(['success' => true, 'message' => 'Festivo eliminado']);
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

    private function fetchNagerHolidays(int $year): Response
    {
        return Http::timeout(10)->acceptJson()->get('https://date.nager.at/api/v3/PublicHolidays/' . $year . '/ES');
    }

    private function importHolidaysFromNager(array $holidays, int $createdBy): array
    {
        $zones = DB::table('zones')->select(['id', 'region_code'])->whereNotNull('region_code')->where('region_code', '!=', '')->get();
        $imported = 0;
        $skipped = 0;

        foreach ($holidays as $holiday) {
            $recurring = $this->isRecurringHoliday($holiday) ? 1 : 0;
            $counties = $holiday['counties'] ?? [];

            if ($counties === []) {
                if ($this->zoneHolidayExists(null, (string) $holiday['date'], $recurring)) {
                    $skipped++;
                    continue;
                }

                ZoneHoliday::query()->create([
                    'id' => $this->nextLegacyId('zone_holidays'),
                    'zone_id' => null,
                    'name' => $holiday['localName'] ?? $holiday['name'],
                    'date' => $holiday['date'],
                    'type' => 'national',
                    'recurring' => $recurring,
                    'created_by' => $createdBy,
                ]);
                $imported++;
                continue;
            }

            foreach ($zones as $zone) {
                if (! in_array($zone->region_code, $counties, true)) {
                    continue;
                }

                if ($this->zoneHolidayExists((int) $zone->id, (string) $holiday['date'], $recurring)) {
                    $skipped++;
                    continue;
                }

                ZoneHoliday::query()->create([
                    'id' => $this->nextLegacyId('zone_holidays'),
                    'zone_id' => $zone->id,
                    'name' => $holiday['localName'] ?? $holiday['name'],
                    'date' => $holiday['date'],
                    'type' => 'regional',
                    'recurring' => $recurring,
                    'created_by' => $createdBy,
                ]);
                $imported++;
            }
        }

        return [$imported, $skipped];
    }

    private function zoneHolidayExists(?int $zoneId, string $date, int $recurring): bool
    {
        $query = DB::table('zone_holidays')->where('recurring', $recurring);
        if ($zoneId === null) {
            $query->whereNull('zone_id');
        } else {
            $query->where('zone_id', $zoneId);
        }

        if ($recurring === 1) {
            return $query
                ->whereMonth('date', substr($date, 5, 2))
                ->whereDay('date', substr($date, 8, 2))
                ->exists();
        }

        return $query->whereDate('date', $date)->exists();
    }

    private function isRecurringHoliday(array $holiday): bool
    {
        return ! in_array($holiday['name'] ?? '', self::NON_RECURRENT_HOLIDAYS, true)
            && ! in_array($holiday['localName'] ?? '', self::NON_RECURRENT_HOLIDAYS, true);
    }

    private function adjustHolidayForYear(object $holiday, int $year): ?array
    {
        $date = (string) $holiday->date;
        if ((int) $holiday->recurring === 1) {
            $date = $year . '-' . substr($date, 5);
        } elseif ((int) substr($date, 0, 4) !== $year) {
            return null;
        }

        return [
            'id' => (int) $holiday->id,
            'zone_id' => $holiday->zone_id !== null ? (int) $holiday->zone_id : null,
            'name' => $holiday->name,
            'date' => $date,
            'type' => $holiday->type,
            'recurring' => (bool) $holiday->recurring,
            'created_by' => $holiday->created_by,
            'created_by_name' => $holiday->created_by_name,
        ];
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}
