<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
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

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $query = User::query()
            ->with(['zone:id,name', 'calendar:id,name'])
            ->orderBy('name');

        if ($authUser->role === 'coordinator' && ! $this->canCoordinatorSeeAllUsers($authUser)) {
            $query->where('zone_id', $authUser->zone_id);
        }

        $users = $query->get()->map(fn (User $user): array => $this->legacyApiAuth->serializeUser($user))->values();

        return response()->json([
            'success' => true,
            'users' => $users,
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
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:4'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:255'],
            'dni' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'coordinator', 'employee'])],
            'zoneId' => ['nullable', 'integer'],
            'work_hours' => ['nullable', 'numeric'],
            'weekly_hours' => ['nullable', 'numeric'],
            'schedule_start' => ['nullable', 'date_format:H:i:s'],
            'schedule_end' => ['nullable', 'date_format:H:i:s'],
            'schedule_start_2' => ['nullable', 'date_format:H:i:s'],
            'schedule_end_2' => ['nullable', 'date_format:H:i:s'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Campos requeridos faltantes o invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($authUser->role === 'coordinator') {
            if ($data['role'] !== 'employee') {
                return $this->forbidden('Solo puedes crear empleados');
            }

            $data['zoneId'] = (int) $authUser->zone_id;
        }

        $user = User::query()->create([
            'username' => $data['username'],
            'password_hash' => Hash::make($data['password']),
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'dni' => $data['dni'] ?? null,
            'role' => $data['role'],
            'zone_id' => $data['zoneId'] ?? null,
            'active' => true,
            'work_hours' => isset($data['work_hours']) ? (float) $data['work_hours'] : 8.0,
            'weekly_hours' => isset($data['weekly_hours']) ? (float) $data['weekly_hours'] : 40.0,
            'schedule_start' => $data['schedule_start'] ?? '09:00:00',
            'schedule_end' => $data['schedule_end'] ?? '18:00:00',
            'schedule_start_2' => $data['schedule_start_2'] ?? null,
            'schedule_end_2' => $data['schedule_end_2'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
            ],
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return $this->unauthorized();
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return $this->forbidden('Sin permisos');
        }

        $user = User::query()->with(['zone:id,name', 'calendar:id,name'])->find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        if ($authUser->role === 'coordinator'
            && ! $this->canCoordinatorSeeAllUsers($authUser)
            && (int) $user->zone_id !== (int) $authUser->zone_id) {
            return $this->forbidden('Solo puedes ver usuarios de tu zona');
        }

        return response()->json([
            'success' => true,
            'user' => $this->legacyApiAuth->serializeUser($user),
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

        $targetUser = User::query()->find($id);
        if (! $targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        if ($authUser->role === 'coordinator') {
            if ((int) $targetUser->zone_id !== (int) $authUser->zone_id) {
                return $this->forbidden('Solo puedes editar usuarios de tu zona');
            }

            if ($request->has('zoneId') && (int) $request->input('zoneId') !== (int) $authUser->zone_id) {
                return $this->forbidden('Solo puedes asignar usuarios a tu zona');
            }
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'max:255', Rule::unique('users', 'username')->ignore($targetUser->id)],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($targetUser->id)],
            'phone' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', Rule::in(['admin', 'coordinator', 'employee'])],
            'zoneId' => ['nullable', 'integer'],
            'password' => ['nullable', 'string', 'min:4'],
            'work_hours' => ['sometimes', 'numeric'],
            'weekly_hours' => ['sometimes', 'numeric'],
            'schedule_start' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'schedule_end' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'schedule_start_2' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'schedule_end_2' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'calendar_id' => ['sometimes', 'nullable', 'integer'],
            'can_view_reports' => ['sometimes', 'boolean'],
            'can_view_all_records' => ['sometimes', 'boolean'],
            'can_view_all_bolsa' => ['sometimes', 'boolean'],
            'can_view_all_dashboard' => ['sometimes', 'boolean'],
            'can_view_user_overview' => ['sometimes', 'boolean'],
            'can_view_coordinators_in_employee_view' => ['sometimes', 'boolean'],
            'can_view_all_vacations' => ['sometimes', 'boolean'],
            'can_promote_to_coordinator' => ['sometimes', 'boolean'],
            'can_view_reports_zone_ids' => ['sometimes', 'nullable', 'array'],
            'can_view_all_records_zone_ids' => ['sometimes', 'nullable', 'array'],
            'can_view_all_bolsa_zone_ids' => ['sometimes', 'nullable', 'array'],
            'can_view_all_dashboard_zone_ids' => ['sometimes', 'nullable', 'array'],
            'can_view_user_overview_zone_ids' => ['sometimes', 'nullable', 'array'],
            'can_view_coordinators_in_employee_view_zone_ids' => ['sometimes', 'nullable', 'array'],
            'can_view_all_vacations_zone_ids' => ['sometimes', 'nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (array_key_exists('role', $data) && $authUser->role === 'coordinator') {
            if (! $this->legacyApiAuth->userHasAccess($authUser, 'can_promote_to_coordinator')) {
                return $this->forbidden('No puedes cambiar el rol de este usuario');
            }

            if (! in_array($targetUser->role, ['employee', 'coordinator'], true)
                || ! in_array($data['role'], ['employee', 'coordinator'], true)) {
                return $this->forbidden('Solo puedes promocionar empleados a coordinador o revertirlos a empleado');
            }
        }

        $updates = [];

        foreach (['name', 'username', 'email', 'phone', 'work_hours', 'weekly_hours', 'schedule_start', 'schedule_end', 'schedule_start_2', 'schedule_end_2'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('active', $data)) {
            $updates['active'] = (bool) $data['active'];
        }

        if (array_key_exists('role', $data)) {
            $updates['role'] = $data['role'];
        }

        if (array_key_exists('zoneId', $data)) {
            $updates['zone_id'] = $data['zoneId'];
        }

        if (array_key_exists('calendar_id', $data)) {
            $updates['calendar_id'] = $data['calendar_id'];
        }

        if (! empty($data['password'] ?? null)) {
            $updates['password_hash'] = Hash::make($data['password']);
        }

        $accessColumns = [
            'can_view_reports',
            'can_view_all_records',
            'can_view_all_bolsa',
            'can_view_all_dashboard',
            'can_view_user_overview',
            'can_view_coordinators_in_employee_view',
            'can_view_all_vacations',
            'can_promote_to_coordinator',
        ];

        $scopedColumns = [
            'can_view_reports_zone_ids' => 'can_view_reports',
            'can_view_all_records_zone_ids' => 'can_view_all_records',
            'can_view_all_bolsa_zone_ids' => 'can_view_all_bolsa',
            'can_view_all_dashboard_zone_ids' => 'can_view_all_dashboard',
            'can_view_user_overview_zone_ids' => 'can_view_user_overview',
            'can_view_coordinators_in_employee_view_zone_ids' => 'can_view_coordinators_in_employee_view',
            'can_view_all_vacations_zone_ids' => 'can_view_all_vacations',
        ];

        foreach ($accessColumns as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }

            if ($authUser->role !== 'admin') {
                return $this->forbidden('Solo el administrador puede cambiar permisos avanzados');
            }

            $updates[$column] = (bool) $data[$column];
        }

        foreach ($scopedColumns as $column => $linkedPermission) {
            if (! array_key_exists($column, $data)) {
                continue;
            }

            if ($authUser->role !== 'admin') {
                return $this->forbidden('Solo el administrador puede cambiar permisos avanzados');
            }

            $zoneIds = $this->legacyApiAuth->normalizeZoneIdList($data[$column] ?? []);
            $updates[$column] = $zoneIds === [] ? null : json_encode($zoneIds, JSON_THROW_ON_ERROR);
            $updates[$linkedPermission] = $zoneIds !== [];
        }

        if ($updates === []) {
            return response()->json([
                'success' => false,
                'message' => 'No hay campos para actualizar',
            ], 400);
        }

        $targetUser->fill($updates);
        $targetUser->save();

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado',
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

        $targetUser = User::query()->find($id);
        if (! $targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        if ($authUser->role === 'coordinator'
            && ($targetUser->role !== 'employee' || (int) $targetUser->zone_id !== (int) $authUser->zone_id)) {
            return $this->forbidden('Solo puedes eliminar empleados de tu zona');
        }

        if ((int) $targetUser->id === 5) {
            return $this->forbidden('No se puede eliminar el administrador principal');
        }

        $targetUser->delete();

        return response()->json([
            'success' => true,
            'message' => 'Usuario eliminado',
        ]);
    }

    private function canCoordinatorSeeAllUsers(User $user): bool
    {
        foreach ([
            'can_view_all_records',
            'can_view_reports',
            'can_view_all_bolsa',
            'can_view_all_dashboard',
            'can_view_all_vacations',
            'can_view_coordinators_in_employee_view',
            'can_promote_to_coordinator',
        ] as $permission) {
            if ($this->legacyApiAuth->userHasAccess($user, $permission)) {
                return true;
            }
        }

        return false;
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
