<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use App\Support\LegacyApiAuth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ZoneController extends Controller
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

        $zones = Zone::query()
            ->select(['id', 'name', 'address', 'postal_code', 'city', 'province', 'region_code', 'qr_code', 'created_at'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'zones' => $zones,
        ]);
    }

    public function minimal(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $zones = Zone::query()
            ->select(['id', 'name', 'created_at'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'zones' => $zones,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        if ($authUser->role !== 'admin') {
            return $this->forbidden('Solo administradores pueden crear zonas');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'postalCode' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'regionCode' => ['nullable', 'string', 'max:10'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'El nombre de la zona es requerido',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $zone = Zone::query()->create([
            'id' => $this->nextLegacyId('zones'),
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'postal_code' => $data['postalCode'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? null,
            'region_code' => $data['regionCode'] ?? null,
            'qr_code' => $this->generateQrCode('ZONE', Zone::query()),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Zona creada correctamente',
            'zone' => [
                'id' => $zone->id,
                'name' => $zone->name,
                'qr_code' => $zone->qr_code,
            ],
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        $zone = Zone::query()->find($id);

        if (! $zone) {
            return response()->json([
                'success' => false,
                'message' => 'Zona no encontrada',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'zone' => $zone,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        if ($authUser->role !== 'admin') {
            return $this->forbidden('Solo administradores pueden editar zonas');
        }

        $zone = Zone::query()->find($id);

        if (! $zone) {
            return response()->json([
                'success' => false,
                'message' => 'Zona no encontrada',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postalCode' => ['sometimes', 'nullable', 'string', 'max:10'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'province' => ['sometimes', 'nullable', 'string', 'max:100'],
            'regionCode' => ['sometimes', 'nullable', 'string', 'max:10'],
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

        if (array_key_exists('name', $data)) {
            $updates['name'] = $data['name'];
        }
        if (array_key_exists('address', $data)) {
            $updates['address'] = $data['address'];
        }
        if (array_key_exists('postalCode', $data)) {
            $updates['postal_code'] = $data['postalCode'];
        }
        if (array_key_exists('city', $data)) {
            $updates['city'] = $data['city'];
        }
        if (array_key_exists('province', $data)) {
            $updates['province'] = $data['province'];
        }
        if (array_key_exists('regionCode', $data)) {
            $updates['region_code'] = $data['regionCode'];
        }

        if ($updates === []) {
            return response()->json([
                'success' => false,
                'message' => 'No hay campos para actualizar',
            ], 400);
        }

        $zone->fill($updates);
        $zone->save();

        return response()->json([
            'success' => true,
            'message' => 'Zona actualizada',
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        if ($authUser->role !== 'admin') {
            return $this->forbidden('Solo administradores pueden eliminar zonas');
        }

        $zone = Zone::query()->find($id);

        if (! $zone) {
            return response()->json([
                'success' => false,
                'message' => 'Zona no encontrada',
            ], 404);
        }

        if ($zone->users()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar la zona porque tiene usuarios asignados',
            ], 400);
        }

        if ($zone->clients()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar la zona porque tiene clientes asignados',
            ], 400);
        }

        $zone->delete();

        return response()->json([
            'success' => true,
            'message' => 'Zona eliminada correctamente',
        ]);
    }

    public function regenerateQr(Request $request, string $id): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $authUser) {
            return $this->unauthorized();
        }

        if ($authUser->role !== 'admin') {
            return $this->forbidden('Solo administradores pueden regenerar QR');
        }

        $zone = Zone::query()->find($id);

        if (! $zone) {
            return response()->json([
                'success' => false,
                'message' => 'Zona no encontrada',
            ], 404);
        }

        $zone->qr_code = $this->generateQrCode('ZONE', Zone::query()->whereKeyNot($zone->getKey()));
        $zone->save();

        return response()->json([
            'success' => true,
            'message' => 'Código QR regenerado',
            'qr_code' => $zone->qr_code,
        ]);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }

    private function generateQrCode(string $prefix, Builder $query): string
    {
        do {
            $code = $prefix.strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8));
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
