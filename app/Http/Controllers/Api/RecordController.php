<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Record;
use App\Models\User;
use App\Models\Zone;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RecordController extends Controller
{
    public function __construct(private readonly LegacyApiAuth $legacyApiAuth)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return $this->unauthorized();
        }

        $query = Record::query()
            ->with([
                'employee:id,name,role,zone_id',
                'client:id,name,zone_id',
                'client.zone:id,name',
                'zone:id,name',
            ]);

        $this->applyRecordVisibility($query, $authUser);

        if ($request->filled('employeeId')) {
            $query->where('employee_id', (int) $request->integer('employeeId'));
        }

        if ($request->filled('startDate')) {
            $query->whereDate('timestamp', '>=', $request->string('startDate')->toString());
        }

        if ($request->filled('endDate')) {
            $query->whereDate('timestamp', '<=', $request->string('endDate')->toString());
        }

        $records = $query
            ->orderByDesc('timestamp')
            ->limit(100)
            ->get()
            ->map(fn (Record $record): array => $this->serializeRecord($record))
            ->values();

        return response()->json([
            'success' => true,
            'records' => $records,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return $this->unauthorized();
        }

        $validator = Validator::make($request->all(), [
            'type' => ['required', Rule::in(['entrada', 'salida'])],
            'clientId' => ['nullable', 'integer'],
            'zoneId' => ['nullable', 'integer'],
            'qrCode' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'device' => ['nullable', 'string', 'max:255'],
            'isTeletrabajo' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo invalido o datos incorrectos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $clientId = $data['clientId'] ?? null;
        $zoneId = $data['zoneId'] ?? null;
        $qrCode = isset($data['qrCode']) ? trim((string) $data['qrCode']) : null;

        if ($clientId !== null && $clientId <= 0) {
            $clientId = null;
        }

        if ($zoneId !== null && $zoneId <= 0) {
            $zoneId = null;
        }

        if ($clientId === null && $zoneId === null && $qrCode !== null && $qrCode !== '') {
            [$clientId, $zoneId] = $this->resolveLocationFromQr($qrCode);
        }

        if ($clientId !== null) {
            $zoneId = null;
        } elseif ($zoneId !== null) {
            $clientId = null;
        }

        $device = (string) ($data['device'] ?? 'Web');

        if ($clientId !== null) {
            $client = Client::query()->with('zone:id,name')->find($clientId);

            if (! $client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado',
                    'clientId' => $clientId,
                ], 404);
            }

            $device = 'Cliente: '.$client->name;
        } elseif ($zoneId !== null) {
            $zone = Zone::query()->find($zoneId);

            if (! $zone) {
                return response()->json([
                    'success' => false,
                    'message' => 'Zona no encontrada',
                ], 404);
            }

            $device = 'Zona: '.$zone->name;
        }

        $record = Record::query()->create([
            'id' => $this->nextLegacyId('records'),
            'employee_id' => $authUser->id,
            'type' => $data['type'],
            'timestamp' => now(),
            'client_id' => $clientId,
            'zone_id' => $zoneId,
            'latitude' => $data['latitude'] ?? 0,
            'longitude' => $data['longitude'] ?? 0,
            'device' => $device,
            'is_teletrabajo' => (bool) ($data['isTeletrabajo'] ?? false),
            'confirmed' => false,
        ]);

        return response()->json([
            'success' => true,
            'record_id' => $record->id,
            'message' => 'Fichaje registrado',
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return $this->unauthorized();
        }

        $record = Record::query()
            ->with([
                'employee:id,name,role,zone_id',
                'client:id,name,zone_id',
                'client.zone:id,name',
                'zone:id,name',
            ])
            ->find($id);

        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Fichaje no encontrado',
            ], 404);
        }

        if (! $this->canAccessRecord($authUser, $record)) {
            return $this->forbidden('Sin permisos para ver este fichaje');
        }

        return response()->json([
            'success' => true,
            'record' => $this->serializeRecord($record),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return $this->unauthorized();
        }

        if ($authUser->role !== 'admin') {
            return $this->forbidden('Solo admin puede actualizar fichajes');
        }

        $record = Record::query()->find($id);
        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Fichaje no encontrado',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'type' => ['sometimes', Rule::in(['entrada', 'salida'])],
            'timestamp' => ['sometimes', 'date'],
            'clientId' => ['sometimes', 'nullable', 'integer'],
            'zoneId' => ['sometimes', 'nullable', 'integer'],
            'latitude' => ['sometimes', 'nullable', 'numeric'],
            'longitude' => ['sometimes', 'nullable', 'numeric'],
            'device' => ['sometimes', 'nullable', 'string', 'max:255'],
            'isTeletrabajo' => ['sometimes', 'boolean'],
            'confirmed' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $updates = [];

        if (array_key_exists('type', $data)) {
            $updates['type'] = $data['type'];
        }
        if (array_key_exists('timestamp', $data)) {
            $updates['timestamp'] = $data['timestamp'];
        }
        if (array_key_exists('clientId', $data)) {
            $updates['client_id'] = $data['clientId'];
            if ($data['clientId'] !== null) {
                $updates['zone_id'] = null;
            }
        }
        if (array_key_exists('zoneId', $data)) {
            $updates['zone_id'] = $data['zoneId'];
            if ($data['zoneId'] !== null) {
                $updates['client_id'] = null;
            }
        }
        if (array_key_exists('latitude', $data)) {
            $updates['latitude'] = $data['latitude'];
        }
        if (array_key_exists('longitude', $data)) {
            $updates['longitude'] = $data['longitude'];
        }
        if (array_key_exists('device', $data)) {
            $updates['device'] = $data['device'];
        }
        if (array_key_exists('isTeletrabajo', $data)) {
            $updates['is_teletrabajo'] = (bool) $data['isTeletrabajo'];
        }
        if (array_key_exists('confirmed', $data)) {
            $updates['confirmed'] = (bool) $data['confirmed'];
        }

        if ($updates === []) {
            return response()->json([
                'success' => false,
                'message' => 'No hay campos para actualizar',
            ], 400);
        }

        $record->fill($updates);
        $record->save();

        return response()->json([
            'success' => true,
            'message' => 'Fichaje actualizado',
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return $this->unauthorized();
        }

        if ($authUser->role !== 'admin') {
            return $this->forbidden('Solo admin puede eliminar fichajes');
        }

        $record = Record::query()->find($id);
        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Fichaje no encontrado',
            ], 404);
        }

        $record->delete();

        return response()->json([
            'success' => true,
            'message' => 'Fichaje eliminado correctamente',
        ]);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos para confirmar fichajes');
        }

        $record = Record::query()->with('employee:id,role,zone_id')->find($id);
        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Fichaje no encontrado',
            ], 404);
        }

        if (! $this->canAccessRecord($authUser, $record)) {
            return $this->forbidden('Sin permisos para confirmar este fichaje');
        }

        $record->forceFill(['confirmed' => true])->save();

        return response()->json([
            'success' => true,
            'message' => 'Fichaje confirmado',
        ]);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }

    private function applyRecordVisibility($query, User $authUser): void
    {
        if ($authUser->role === 'employee') {
            $query->where('employee_id', $authUser->id);
            return;
        }

        if ($authUser->role !== 'coordinator') {
            return;
        }

        $zoneScope = $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_all_records');

        if ($zoneScope === null) {
            $query->whereHas('employee', static fn ($employeeQuery) => $employeeQuery->where('role', '!=', 'admin'));
            return;
        }

        if ($zoneScope === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereHas('employee', static fn ($employeeQuery) => $employeeQuery->whereIn('zone_id', $zoneScope));
    }

    private function canAccessRecord(User $authUser, Record $record): bool
    {
        $record->loadMissing('employee:id,role,zone_id');

        if ($authUser->role === 'admin') {
            return true;
        }

        if ($authUser->role === 'employee') {
            return (int) $record->employee_id === (int) $authUser->id;
        }

        if ($authUser->role !== 'coordinator') {
            return false;
        }

        $zoneScope = $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_all_records');

        if ($zoneScope === null) {
            return $record->employee?->role !== 'admin';
        }

        if ($zoneScope === []) {
            return false;
        }

        return in_array((int) ($record->employee?->zone_id ?? 0), $zoneScope, true);
    }

    private function resolveLocationFromQr(string $qrCode): array
    {
        $clientId = null;
        $zoneId = null;

        if (filter_var($qrCode, FILTER_VALIDATE_URL)) {
            $queryString = parse_url($qrCode, PHP_URL_QUERY);
            $qrParams = [];

            if (! empty($queryString)) {
                parse_str($queryString, $qrParams);
                $qrType = strtolower(trim((string) ($qrParams['qr'] ?? '')));
                $qrId = isset($qrParams['id']) && is_numeric($qrParams['id']) ? (int) $qrParams['id'] : null;

                if ($qrId && in_array($qrType, ['zona', 'zone'], true)) {
                    $zoneId = $qrId;
                }

                if ($qrId && in_array($qrType, ['cliente', 'client'], true)) {
                    $clientId = $qrId;
                }
            }
        }

        if ($clientId === null && $zoneId === null) {
            $zone = Zone::query()->where('qr_code', $qrCode)->first();
            if ($zone) {
                $zoneId = (int) $zone->id;
            }
        }

        if ($clientId === null && $zoneId === null) {
            $client = Client::query()->where('qr_code', $qrCode)->first();
            if ($client) {
                $clientId = (int) $client->id;
            }
        }

        return [$clientId, $zoneId];
    }

    private function serializeRecord(Record $record): array
    {
        return array_merge($record->withoutRelations()->toArray(), [
            'employee_name' => $record->employee?->name,
            'client_name' => $record->client?->name,
            'zone_name' => $record->zone?->name ?? $record->client?->zone?->name,
        ]);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'No autorizado',
        ], 401);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }
}

