<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ScheduleAssignment;
use App\Models\Zone;
use App\Support\LegacyApiAuth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ClientController extends Controller
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

        if ($request->filled('id')) {
            return $this->show($request, (string) $request->integer('id'));
        }

        $query = Client::query()
            ->select([
                'clients.id',
                'clients.name',
                'clients.dni',
                'clients.address',
                'clients.postal_code',
                'clients.city',
                'clients.province',
                'clients.phone',
                'clients.email',
                'clients.notes',
                'clients.zone_id',
                'clients.qr_code',
                'clients.is_office',
                'clients.latitude',
                'clients.longitude',
                'clients.active',
                'clients.created_at',
            ])
            ->with('zone:id,name')
            ->orderBy('clients.name');

        if ($authUser->role === 'employee') {
            $weekStart = $request->query('week_start', now()->startOfWeek()->format('Y-m-d'));
            $query->whereIn('clients.id', $this->assignedClientIdsForEmployee($authUser->id, $weekStart));
        }

        if ($authUser->role === 'coordinator') {
            $zoneScope = $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_user_overview');

            if ($zoneScope === []) {
                return response()->json([
                    'success' => true,
                    'clients' => [],
                ]);
            }

            if ($zoneScope !== null) {
                $query->whereIn('clients.zone_id', $zoneScope);
            }
        }

        $clients = $query->get()->map(fn (Client $client): array => $this->serializeClient($client))->values();

        return response()->json([
            'success' => true,
            'clients' => $clients,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:200'],
            'dni' => ['nullable', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:255'],
            'postalCode' => ['required', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'zoneId' => ['nullable', 'integer'],
            'isOffice' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Campos requeridos faltantes',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($authUser->role === 'coordinator') {
            $data['zoneId'] = (int) $authUser->zone_id;
        }

        $coords = $this->geocodeAddress($data['address'], $data['city'], $data['postalCode']);

        $client = Client::query()->create([
            'id' => $this->nextLegacyId('clients'),
            'name' => $data['name'],
            'dni' => $data['dni'] ?? null,
            'address' => $data['address'],
            'postal_code' => $data['postalCode'],
            'city' => $data['city'],
            'province' => $data['province'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'zone_id' => $data['zoneId'] ?? null,
            'qr_code' => $this->generateQrCode('CLI', Client::query()),
            'is_office' => (bool) ($data['isOffice'] ?? false),
            'active' => true,
            'latitude' => $coords['latitude'] ?? null,
            'longitude' => $coords['longitude'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cliente creado'.($coords ? ' con coordenadas' : ' (sin coordenadas)'),
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'qr_code' => $client->qr_code,
                'latitude' => $client->latitude,
                'longitude' => $client->longitude,
            ],
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser) {
            return $this->unauthorized();
        }

        $client = Client::query()->with('zone:id,name')->find($id);

        if (! $client) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado',
            ], 404);
        }

        if ($authUser->role === 'employee') {
            $weekStart = $request->query('week_start', now()->startOfWeek()->format('Y-m-d'));
            if (! in_array((int) $client->id, $this->assignedClientIdsForEmployee($authUser->id, $weekStart), true)) {
                return $this->forbidden('Solo puedes ver usuarios que tengas asignados');
            }
        }

        if ($authUser->role === 'coordinator') {
            $zoneScope = $this->legacyApiAuth->getAccessibleZoneScope($authUser, 'can_view_user_overview');
            if ($zoneScope !== null && ! in_array((int) $client->zone_id, $zoneScope, true)) {
                return $this->forbidden('Solo puedes ver clientes de tu zona o de las zonas autorizadas');
            }
        }

        return response()->json([
            'success' => true,
            'client' => $this->serializeClient($client),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $client = Client::query()->find($id);

        if (! $client) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado',
            ], 404);
        }

        if ($authUser->role === 'coordinator' && (int) $client->zone_id !== (int) $authUser->zone_id) {
            return $this->forbidden('Solo puedes editar clientes de tu zona');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:200'],
            'dni' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postalCode' => ['sometimes', 'nullable', 'string', 'max:10'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'province' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'zoneId' => ['sometimes', 'nullable', 'integer'],
            'isOffice' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'latitude' => ['sometimes', 'nullable', 'numeric'],
            'longitude' => ['sometimes', 'nullable', 'numeric'],
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

        foreach (['name', 'dni', 'address', 'city', 'province', 'phone', 'email', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('postalCode', $data)) {
            $updates['postal_code'] = $data['postalCode'];
        }

        if (array_key_exists('zoneId', $data)) {
            $updates['zone_id'] = $authUser->role === 'coordinator' ? (int) $authUser->zone_id : $data['zoneId'];
        }

        if (array_key_exists('isOffice', $data)) {
            $updates['is_office'] = (bool) $data['isOffice'];
        }

        if (array_key_exists('active', $data)) {
            $updates['active'] = (bool) $data['active'];
        }

        $shouldGeocode = array_key_exists('address', $data) || array_key_exists('city', $data) || array_key_exists('postalCode', $data);
        if ($shouldGeocode) {
            $address = $data['address'] ?? $client->address ?? '';
            $city = $data['city'] ?? $client->city ?? '';
            $postalCode = $data['postalCode'] ?? $client->postal_code ?? '';
            $coords = $this->geocodeAddress($address, $city, $postalCode);

            if ($coords) {
                $updates['latitude'] = $coords['latitude'];
                $updates['longitude'] = $coords['longitude'];
            }
        }

        if (array_key_exists('latitude', $data)) {
            $updates['latitude'] = $data['latitude'];
        }

        if (array_key_exists('longitude', $data)) {
            $updates['longitude'] = $data['longitude'];
        }

        if ($updates === []) {
            return response()->json([
                'success' => false,
                'message' => 'No hay campos para actualizar',
            ], 400);
        }

        $client->fill($updates);
        $client->save();

        return response()->json([
            'success' => true,
            'message' => 'Cliente actualizado'.(($shouldGeocode ?? false) && ! empty($coords) ? ' con coordenadas actualizadas' : ''),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser) {
            return $this->unauthorized();
        }

        if ($authUser->role !== 'admin') {
            return $this->forbidden('Solo admin puede eliminar');
        }

        $client = Client::query()->find($id);

        if (! $client) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado',
            ], 404);
        }

        if (DB::table('records')->where('client_id', $client->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente tiene fichajes asociados',
            ], 400);
        }

        $client->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cliente eliminado',
        ]);
    }

    public function geocode(Request $request): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser) {
            return $this->unauthorized();
        }

        $validator = Validator::make($request->all(), [
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'postalCode' => ['nullable', 'string', 'max:10'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dirección y ciudad son obligatorias',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $coords = $this->geocodeAddress($data['address'], $data['city'], $data['postalCode'] ?? '');

        if (! $coords) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron coordenadas para esta dirección',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'latitude' => $coords['latitude'],
            'longitude' => $coords['longitude'],
        ]);
    }

    public function geocodeAll(Request $request): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser) {
            return $this->unauthorized();
        }

        if ($authUser->role !== 'admin') {
            return $this->forbidden('Solo el admin puede geocodificar en lote');
        }

        $pendingClients = Client::query()
            ->where(static function (Builder $query): void {
                $query->whereNull('latitude')->orWhereNull('longitude');
            })
            ->get();

        $geocoded = 0;
        $failed = 0;
        $failedDetails = [];

        foreach ($pendingClients as $client) {
            $coords = $this->geocodeAddress((string) ($client->address ?? ''), (string) ($client->city ?? ''), (string) ($client->postal_code ?? ''));

            if ($coords) {
                $client->fill([
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ])->save();
                $geocoded++;
                continue;
            }

            $failed++;
            $failedDetails[] = 'Cliente #'.$client->id.': '.trim((string) $client->address.' '.(string) $client->city);
        }

        return response()->json([
            'success' => true,
            'message' => "Geocodificados: {$geocoded}, fallidos: {$failed}",
            'geocoded' => $geocoded,
            'failed' => $failed,
            'failed_details' => $failedDetails,
        ]);
    }

    public function lookupByQr(Request $request, string $qrCode): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser) {
            return $this->unauthorized();
        }

        $decodedQrCode = urldecode($qrCode);

        $zone = Zone::query()
            ->select(['id', 'name', 'address', 'postal_code', 'city', 'province', 'qr_code'])
            ->where('qr_code', $decodedQrCode)
            ->first();

        if ($zone) {
            return response()->json([
                'success' => true,
                'type' => 'zone',
                'zone' => $zone,
            ]);
        }

        $client = Client::query()
            ->with('zone:id,name')
            ->where('qr_code', $decodedQrCode)
            ->first();

        if ($client) {
            return response()->json([
                'success' => true,
                'type' => 'client',
                'client' => $this->serializeClient($client),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Código QR no válido o no encontrado',
        ], 404);
    }

    public function regenerateQr(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $client = Client::query()->find($id);

        if (! $client) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado',
            ], 404);
        }

        if ($authUser->role === 'coordinator' && (int) $client->zone_id !== (int) $authUser->zone_id) {
            return $this->forbidden('Solo puedes regenerar QR de clientes de tu zona');
        }

        $client->qr_code = $this->generateQrCode('CLI', Client::query()->whereKeyNot($client->getKey()));
        $client->save();

        return response()->json([
            'success' => true,
            'message' => 'Código QR regenerado',
            'qr_code' => $client->qr_code,
        ]);
    }

    private function serializeClient(Client $client): array
    {
        return array_merge($client->withoutRelations()->toArray(), [
            'zone_name' => $client->zone?->name,
        ]);
    }

    private function assignedClientIdsForEmployee(int $employeeId, string $weekStart): array
    {
        return ScheduleAssignment::query()
            ->select('client_id')
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->whereNotNull('client_id')
            ->whereHas('schedule', static function (Builder $query) use ($weekStart): void {
                $query->where('week_start', '<=', $weekStart);
            })
            ->distinct()
            ->pluck('client_id')
            ->map(static fn ($clientId): int => (int) $clientId)
            ->values()
            ->all();
    }

    private function geocodeAddress(string $address, string $city, string $postalCode, string $country = 'Spain'): ?array
    {
        $query = trim($address.', '.$postalCode.' '.$city.', '.$country);

        if ($query === '' || trim($address) === '' || trim($city) === '') {
            return null;
        }

        $response = Http::timeout(8)
            ->withUserAgent('SistemaFichaje/1.0 (control-horario)')
            ->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query,
                'format' => 'json',
                'limit' => 1,
                'addressdetails' => 0,
            ]);

        if (! $response->ok()) {
            return null;
        }

        $results = $response->json();
        if (! is_array($results) || $results === [] || empty($results[0]['lat']) || empty($results[0]['lon'])) {
            return null;
        }

        return [
            'latitude' => (float) $results[0]['lat'],
            'longitude' => (float) $results[0]['lon'],
        ];
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }

    private function generateQrCode(string $prefix, Builder $query): string
    {
        do {
            $code = $prefix.strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 10));
        } while ($query->where('qr_code', $code)->exists());

        return $code;
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

