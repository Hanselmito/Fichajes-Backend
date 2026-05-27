<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Calendar;
use App\Models\CalendarHoliday;
use App\Models\User;
use App\Support\LegacyApiAuth;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CalendarController extends Controller
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
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $calendars = DB::table('calendars as c')
            ->select([
                'c.*',
                'u.name as created_by_name',
                DB::raw('COUNT(ch.id) as holiday_count'),
                DB::raw("SUM(CASE WHEN ch.type = 'regional' THEN 1 ELSE 0 END) as regional_count"),
                DB::raw("SUM(CASE WHEN ch.type = 'local' THEN 1 ELSE 0 END) as local_count"),
                DB::raw('(SELECT COUNT(*) FROM zone_holidays WHERE zone_id IS NULL) as national_count'),
            ])
            ->leftJoin('users as u', 'c.created_by', '=', 'u.id')
            ->leftJoin('calendar_holidays as ch', 'ch.calendar_id', '=', 'c.id')
            ->groupBy('c.id', 'c.name', 'c.description', 'c.region_code', 'c.created_by', 'c.created_at', 'u.name')
            ->orderBy('c.name')
            ->get();

        return response()->json(['success' => true, 'calendars' => $calendars]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'region_code' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $result = DB::transaction(function () use ($data, $authUser): array {
                $calendarId = $this->nextLegacyId('calendars');
                Calendar::query()->create([
                    'id' => $calendarId,
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'region_code' => $data['region_code'] ?? null,
                    'created_by' => $authUser->id,
                ]);

                $imported = 0;
                if (! empty($data['region_code'])) {
                    [$imported] = $this->importRegionalHolidays($calendarId, $data['region_code'], (int) now()->format('Y'), (int) $authUser->id);
                }

                return ['id' => $calendarId, 'imported' => $imported];
            });
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Calendario creado' . ($result['imported'] > 0 ? ' con ' . $result['imported'] . ' festivos autonómicos importados' : ''),
            'id' => $result['id'],
            'imported' => $result['imported'],
        ], 201);
    }

    public function update(Request $request, string $calendar): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $calendarModel = Calendar::query()->find((int) $calendar);
        if (! $calendarModel) {
            return response()->json(['success' => false, 'message' => 'Calendario no encontrado'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'region_code' => ['nullable', 'string', 'max:10'],
        ]);

        if ($data === []) {
            return response()->json(['success' => false, 'message' => 'Nada que actualizar'], 400);
        }

        $calendarModel->fill($data)->save();

        return response()->json(['success' => true, 'message' => 'Calendario actualizado']);
    }

    public function destroy(Request $request, string $calendar): JsonResponse
    {
        $authUser = $this->requireManager($request, true);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $calendarId = (int) $calendar;
        DB::transaction(function () use ($calendarId): void {
            DB::table('users')->where('calendar_id', $calendarId)->update(['calendar_id' => null]);
            DB::table('calendar_holidays')->where('calendar_id', $calendarId)->delete();
            DB::table('calendars')->where('id', $calendarId)->delete();
        });

        return response()->json(['success' => true, 'message' => 'Calendario eliminado']);
    }

    public function holidays(Request $request, string $calendar): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $calendarId = (int) $calendar;
        $year = $request->integer('year', (int) now()->format('Y'));
        if (! Calendar::query()->whereKey($calendarId)->exists()) {
            return response()->json(['success' => false, 'message' => 'Calendario no encontrado'], 404);
        }

        $holidays = DB::table('zone_holidays')
            ->select(['id', DB::raw('NULL as calendar_id'), 'name', 'date', DB::raw("'national' as type"), 'recurring', DB::raw("'national' as source")])
            ->whereNull('zone_id')
            ->get()
            ->concat(DB::table('calendar_holidays')
                ->select(['id', 'calendar_id', 'name', 'date', 'type', 'recurring', DB::raw("'calendar' as source")])
                ->where('calendar_id', $calendarId)
                ->get())
            ->map(fn ($holiday) => $this->adjustHolidayForYear($holiday, $year))
            ->filter()
            ->sortBy('date')
            ->values();

        return response()->json(['success' => true, 'holidays' => $holidays]);
    }

    public function storeHoliday(Request $request, string $calendar): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $calendarId = (int) $calendar;
        if (! Calendar::query()->whereKey($calendarId)->exists()) {
            return response()->json(['success' => false, 'message' => 'Calendario no encontrado'], 404);
        }

        if ($request->boolean('importNager')) {
            $calendarModel = Calendar::query()->find($calendarId);
            if (! $calendarModel || empty($calendarModel->region_code)) {
                return response()->json(['success' => false, 'message' => 'Este calendario no tiene comunidad autónoma asignada'], 400);
            }

            try {
                [$imported, $skipped] = $this->importRegionalHolidays($calendarId, (string) $calendarModel->region_code, $request->integer('year', (int) now()->format('Y')), (int) $authUser->id);
            } catch (RuntimeException $exception) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 500);
            }

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
        ]);

        $holidayId = $this->nextLegacyId('calendar_holidays');
        CalendarHoliday::query()->create([
            'id' => $holidayId,
            'calendar_id' => $calendarId,
            'name' => $data['name'],
            'date' => $data['date'],
            'type' => $data['type'] ?? 'local',
            'recurring' => array_key_exists('recurring', $data) ? (bool) $data['recurring'] : true,
            'created_by' => $authUser->id,
        ]);

        return response()->json(['success' => true, 'message' => 'Festivo añadido', 'id' => $holidayId], 201);
    }

    public function destroyHoliday(Request $request, string $calendar, string $holidayId): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        DB::table('calendar_holidays')
            ->where('calendar_id', (int) $calendar)
            ->where('id', (int) $holidayId)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Festivo eliminado']);
    }

    private function requireManager(Request $request, bool $adminOnly = false): User|JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $allowedRoles = $adminOnly ? ['admin'] : ['admin', 'coordinator'];
        if (! in_array($authUser->role, $allowedRoles, true)) {
            return response()->json(['success' => false, 'message' => $adminOnly ? 'Solo el admin puede eliminar calendarios' : 'Sin permisos'], 403);
        }

        return $authUser;
    }

    private function importRegionalHolidays(int $calendarId, string $regionCode, int $year, int $createdBy): array
    {
        $response = $this->fetchNagerHolidays($year);
        if (! $response->successful()) {
            throw new RuntimeException('No se pudo conectar con Nager.Date');
        }

        $imported = 0;
        $skipped = 0;
        foreach ($response->json() as $holiday) {
            $counties = $holiday['counties'] ?? [];
            if ($counties === [] || ! in_array($regionCode, $counties, true)) {
                continue;
            }

            $recurring = $this->isRecurringHoliday($holiday) ? 1 : 0;
            $duplicateExists = $recurring
                ? DB::table('calendar_holidays')
                    ->where('calendar_id', $calendarId)
                    ->where('recurring', 1)
                    ->whereMonth('date', substr((string) $holiday['date'], 5, 2))
                    ->whereDay('date', substr((string) $holiday['date'], 8, 2))
                    ->exists()
                : DB::table('calendar_holidays')
                    ->where('calendar_id', $calendarId)
                    ->where('recurring', 0)
                    ->whereDate('date', $holiday['date'])
                    ->exists();

            if ($duplicateExists) {
                $skipped++;
                continue;
            }

            CalendarHoliday::query()->create([
                'id' => $this->nextLegacyId('calendar_holidays'),
                'calendar_id' => $calendarId,
                'name' => $holiday['localName'] ?? $holiday['name'],
                'date' => $holiday['date'],
                'type' => 'regional',
                'recurring' => $recurring,
                'created_by' => $createdBy,
            ]);
            $imported++;
        }

        return [$imported, $skipped];
    }

    private function fetchNagerHolidays(int $year): Response
    {
        return Http::timeout(10)->acceptJson()->get('https://date.nager.at/api/v3/PublicHolidays/' . $year . '/ES');
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
            'calendar_id' => $holiday->calendar_id ? (int) $holiday->calendar_id : null,
            'name' => $holiday->name,
            'date' => $date,
            'type' => $holiday->type,
            'recurring' => (bool) $holiday->recurring,
            'source' => $holiday->source,
        ];
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}