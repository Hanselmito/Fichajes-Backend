<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Zone;
use App\Support\LegacyApiAuth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ClientController extends Controller
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
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:200'],
            'dni' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'postalCode' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
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

        $client = Client::query()->create([
            'id' => $this->nextLegacyId('clients'),
            'name' => $data['name'],
            'dni' => $data['dni'] ?? null,
            'address' => $data['address'] ?? null,
            'postal_code' => $data['postalCode'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'zone_id' => $data['zoneId'] ?? null,
            'qr_code' => $this->generateQrCode('CLI', Client::query()),
            'is_office' => (bool) ($data['isOffice'] ?? false),
            'active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cliente creado',
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
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

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
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

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
            'message' => 'Cliente actualizado',
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

        $client = Client::query()->find($id);

        if (! $client) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado',
            ], 404);
        }

        if ($authUser->role === 'coordinator' && (int) $client->zone_id !== (int) $authUser->zone_id) {
            return $this->forbidden('Solo puedes eliminar clientes de tu zona');
        }

        $client->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cliente eliminado',
        ]);
    }

    public function lookupByQr(Request $request, string $qrCode): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

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
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

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
