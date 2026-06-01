<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incidencia;
use App\Models\User;
use App\Support\LegacyApiAuth;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IncidenciaController extends Controller
{
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

        $query = $this->baseQuery();
        $this->applyRoleScope($query, $authUser);

        if ($request->filled('employeeId')) {
            $query->where('i.employee_id', $request->integer('employeeId'));
        }

        if ($request->filled('estado')) {
            $query->where('i.estado', $request->string('estado')->toString());
        }

        if ($request->filled('clientId')) {
            $query->where('i.client_id', $request->integer('clientId'));
        }

        return response()->json([
            'success' => true,
            'incidencias' => $query
                ->orderByRaw("FIELD(i.prioridad, 'urgente', 'alta', 'media', 'baja')")
                ->orderByRaw("FIELD(i.estado, 'pendiente', 'en_revision', 'resuelto', 'cerrado')")
                ->orderByDesc('i.created_at')
                ->limit(100)
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['required', 'string'],
            'clientId' => ['nullable', 'integer'],
            'zoneId' => ['nullable', 'integer'],
            'recordId' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'in:cliente,desplazamiento,material,salud,otro'],
            'prioridad' => ['nullable', 'in:baja,media,alta,urgente'],
            'coordinadorId' => ['nullable', 'integer'],
        ]);

        $incidencia = Incidencia::query()->create([
            'id' => $this->nextLegacyId('incidencias'),
            'employee_id' => $authUser->id,
            'client_id' => $data['clientId'] ?? null,
            'zone_id' => $data['zoneId'] ?? null,
            'record_id' => $data['recordId'] ?? null,
            'tipo' => $data['tipo'] ?? 'otro',
            'prioridad' => $data['prioridad'] ?? 'media',
            'titulo' => $data['titulo'],
            'descripcion' => $data['descripcion'],
            'coordinador_id' => $data['coordinadorId'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'incidencia_id' => (int) $incidencia->id,
            'message' => 'Incidencia reportada correctamente',
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $query = $this->baseQuery()->where('i.id', (int) $id);
        $this->applyRoleScope($query, $authUser);
        $incidencia = $query->first();

        if (! $incidencia) {
            return response()->json(['success' => false, 'message' => 'Incidencia no encontrada'], 404);
        }

        return response()->json(['success' => true, 'incidencia' => $incidencia]);
    }

    public function update(Request $request, string $id = ''): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if ($authUser->role === 'employee') {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $incidenciaId = $id !== '' ? (int) $id : $request->integer('id');
        if (! $incidenciaId) {
            return response()->json(['success' => false, 'message' => 'ID requerido'], 400);
        }

        $incidencia = $this->scopedIncidenciaForManagement($authUser, $incidenciaId);
        if (! $incidencia) {
            return response()->json(['success' => false, 'message' => 'Incidencia no encontrada'], 404);
        }

        $updates = [];
        if ($request->has('estado')) {
            $updates['estado'] = $request->string('estado')->toString();
        }
        if ($request->has('respuesta')) {
            $updates['respuesta'] = $request->input('respuesta');
        }
        if ($request->has('coordinadorId')) {
            $updates['coordinador_id'] = $request->input('coordinadorId');
        }

        if ($updates === []) {
            return response()->json(['success' => false, 'message' => 'Nada que actualizar'], 400);
        }

        if (($updates['estado'] ?? null) !== null && in_array($updates['estado'], ['resuelto', 'cerrado'], true)) {
            $updates['resuelto_at'] = now();
        }

        $incidencia->fill($updates);
        $incidencia->save();

        return response()->json(['success' => true, 'message' => 'Incidencia actualizada']);
    }

    public function destroy(Request $request, string $id = ''): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if ($authUser->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo admin puede eliminar'], 403);
        }

        $incidenciaId = $id !== '' ? (int) $id : $request->integer('id');
        if (! $incidenciaId) {
            return response()->json(['success' => false, 'message' => 'ID requerido'], 400);
        }

        Incidencia::query()->where('id', $incidenciaId)->delete();

        return response()->json(['success' => true, 'message' => 'Incidencia eliminada']);
    }

    private function baseQuery(): Builder
    {
        return DB::table('incidencias as i')
            ->select([
                'i.*',
                'u.name as employee_name',
                'c.name as client_name',
                'z.name as zone_name',
                'coord.name as coordinador_name',
            ])
            ->join('users as u', 'i.employee_id', '=', 'u.id')
            ->leftJoin('clients as c', 'i.client_id', '=', 'c.id')
            ->leftJoin('zones as z', 'i.zone_id', '=', 'z.id')
            ->leftJoin('users as coord', 'i.coordinador_id', '=', 'coord.id');
    }

    private function applyRoleScope(Builder $query, User $authUser): void
    {
        if ($authUser->role === 'employee') {
            $query->where('i.employee_id', $authUser->id);
            return;
        }

        if ($authUser->role === 'coordinator') {
            $query->where(function (Builder $scope) use ($authUser): void {
                $scope->where('i.coordinador_id', $authUser->id)
                    ->orWhere('u.zone_id', $authUser->zone_id);
            });
        }
    }

    private function scopedIncidenciaForManagement(User $authUser, int $incidenciaId): ?Incidencia
    {
        $query = Incidencia::query()
            ->select(['incidencias.*', 'users.zone_id as employee_zone_id', 'users.role as employee_role'])
            ->join('users', 'users.id', '=', 'incidencias.employee_id')
            ->where('incidencias.id', $incidenciaId);

        if ($authUser->role === 'coordinator') {
            $query->where(function ($scope) use ($authUser): void {
                $scope->where('incidencias.coordinador_id', $authUser->id)
                    ->orWhere('users.zone_id', $authUser->zone_id);
            });
        }

        return $query->first();
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}

