<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceController extends Controller
{
    public function __construct(private readonly LegacyApiAuth $legacyApiAuth)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $query = Service::query()->orderBy('name');
        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }

        $services = $query->get()->map(fn (Service $service): array => $service->toArray())->values();

        return response()->json([
            'success' => true,
            'services' => $services,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Datos incompletos', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $service = Service::query()->create([
            'id' => $this->nextLegacyId('services'),
            'name' => $data['name'],
            'duration_minutes' => (int) ($data['duration_minutes'] ?? 60),
            'color' => $data['color'] ?? '#2196F3',
            'active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Servicio creado',
            'id' => $service->id,
            'service' => $service->toArray(),
        ], 201);
    }

    public function update(Request $request, string $serviceId): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $service = Service::query()->find($serviceId);
        if (! $service) {
            return response()->json(['success' => false, 'message' => 'Servicio no encontrado'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:100'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1'],
            'color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Datos invalidos', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if ($data === []) {
            return response()->json(['success' => false, 'message' => 'Nada que actualizar'], 400);
        }

        $service->fill($data)->save();

        return response()->json(['success' => true, 'message' => 'Servicio actualizado']);
    }

    public function destroy(Request $request, string $serviceId): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if ($authUser->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo el admin puede eliminar servicios'], 403);
        }

        $service = Service::query()->find($serviceId);
        if (! $service) {
            return response()->json(['success' => false, 'message' => 'Servicio no encontrado'], 404);
        }

        $service->active = false;
        $service->save();

        return response()->json(['success' => true, 'message' => 'Servicio desactivado']);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) \Illuminate\Support\Facades\DB::table($table)->max('id')) + 1;
    }
}