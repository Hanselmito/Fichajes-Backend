<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BolsaAnotacion;
use App\Models\User;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BolsaAnotacionController extends Controller
{
    public function __construct(
        private readonly LegacyApiAuth $legacyApiAuth,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $employeeId = $request->integer('employeeId', (int) $authUser->id);
        if ($employeeId !== (int) $authUser->id) {
            $target = User::query()->select(['id', 'zone_id'])->find($employeeId);
            if (! $target) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }
            if ($authUser->role === 'employee') {
                return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
            }
            if ($authUser->role === 'coordinator' && (int) $target->zone_id !== (int) $authUser->zone_id) {
                return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
            }
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $dateFrom = $request->string('start_date')->toString();
            $dateTo = $request->string('end_date')->toString();
        } else {
            $month = $request->string('month', now()->format('Y-m'))->toString();
            $dateFrom = $month . '-01';
            $dateTo = date('Y-m-t', strtotime($dateFrom));
        }

        $anotaciones = DB::table('bolsa_anotaciones as ba')
            ->select(['ba.*', 'u.name as created_by_name'])
            ->leftJoin('users as u', 'ba.created_by', '=', 'u.id')
            ->where('ba.employee_id', $employeeId)
            ->whereBetween('ba.date', [$dateFrom, $dateTo])
            ->orderBy('ba.date')
            ->orderBy('ba.created_at')
            ->get();

        return response()->json(['success' => true, 'anotaciones' => $anotaciones]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'text' => ['required', 'string'],
            'affects_hours' => ['nullable', 'boolean'],
            'hours_adjustment' => ['nullable', 'numeric'],
            'color' => ['nullable', 'string', 'max:7'],
        ]);

        $target = $this->resolveTargetEmployee($authUser, (int) $data['employee_id']);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        $affectsHours = (bool) ($data['affects_hours'] ?? false);
        $annotationId = $this->nextLegacyId('bolsa_anotaciones');
        BolsaAnotacion::query()->create([
            'id' => $annotationId,
            'employee_id' => $target->id,
            'date' => $data['date'],
            'text' => $data['text'],
            'affects_hours' => $affectsHours,
            'hours_adjustment' => $affectsHours ? (float) ($data['hours_adjustment'] ?? 0) : 0,
            'color' => $data['color'] ?? '#9C27B0',
            'created_by' => $authUser->id,
        ]);

        return response()->json(['success' => true, 'message' => 'Anotación creada', 'id' => $annotationId], 201);
    }

    public function update(Request $request, string $anotacion): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $annotation = BolsaAnotacion::query()->find((int) $anotacion);
        if (! $annotation) {
            return response()->json(['success' => false, 'message' => 'Anotación no encontrada'], 404);
        }

        $target = $this->resolveTargetEmployee($authUser, (int) $annotation->employee_id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        $data = $request->validate([
            'text' => ['sometimes', 'string'],
            'affects_hours' => ['nullable', 'boolean'],
            'hours_adjustment' => ['nullable', 'numeric'],
            'color' => ['nullable', 'string', 'max:7'],
        ]);

        if ($data === []) {
            return response()->json(['success' => false, 'message' => 'Nada que actualizar'], 400);
        }

        if (array_key_exists('affects_hours', $data) && ! $data['affects_hours']) {
            $data['hours_adjustment'] = 0;
        }
        $annotation->fill($data)->save();

        return response()->json(['success' => true, 'message' => 'Anotación actualizada']);
    }

    public function destroy(Request $request, string $anotacion): JsonResponse
    {
        $authUser = $this->requireManager($request);
        if ($authUser instanceof JsonResponse) {
            return $authUser;
        }

        $annotation = BolsaAnotacion::query()->find((int) $anotacion);
        if (! $annotation) {
            return response()->json(['success' => false, 'message' => 'Anotación no encontrada'], 404);
        }

        $target = $this->resolveTargetEmployee($authUser, (int) $annotation->employee_id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        $annotation->delete();

        return response()->json(['success' => true, 'message' => 'Anotación eliminada']);
    }

    private function requireManager(Request $request): User|JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Solo coordinadores y admin pueden añadir anotaciones'], 403);
        }

        return $authUser;
    }

    private function resolveTargetEmployee(User $authUser, int $employeeId): User|JsonResponse
    {
        $employee = User::query()->select(['id', 'zone_id'])->find($employeeId);
        if (! $employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
        }

        if ($authUser->role === 'coordinator' && (int) $employee->zone_id !== (int) $authUser->zone_id) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        return $employee;
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}