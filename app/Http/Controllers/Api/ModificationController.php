<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModificationConfirmation;
use App\Models\ModificationRequest;
use App\Models\Notification;
use App\Models\Record;
use App\Models\User;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModificationController extends Controller
{
    public function __construct(
        private readonly LegacyApiAuth $legacyApiAuth,
    ) {
    }

    public function indexRequests(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $status = $request->query('status');
        $query = ModificationRequest::query()
            ->select([
                'modification_requests.id',
                'modification_requests.record_id',
                'modification_requests.employee_id',
                'users.name as employee_name',
                'records.timestamp as original_timestamp',
                'modification_requests.new_date',
                'modification_requests.new_time',
                'modification_requests.reason',
                'modification_requests.status',
                'modification_requests.approved_by',
                'modification_requests.approved_at',
                'modification_requests.created_at',
            ])
            ->join('users', 'users.id', '=', 'modification_requests.employee_id')
            ->join('records', 'records.id', '=', 'modification_requests.record_id');

        if ($authUser->role === 'employee') {
            $query->where('modification_requests.employee_id', $authUser->id);
        } elseif ($authUser->role === 'coordinator') {
            $query->where('users.zone_id', $authUser->zone_id);
        }

        if ($status) {
            $query->where('modification_requests.status', $status);
        }

        return response()->json([
            'success' => true,
            'requests' => $query->orderByDesc('modification_requests.created_at')->get(),
        ]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $data = $request->validate([
            'recordId' => ['required', 'integer'],
            'newDate' => ['required', 'date'],
            'newTime' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string'],
        ]);

        $record = Record::query()
            ->where('id', $data['recordId'])
            ->where('employee_id', $authUser->id)
            ->first();

        if (! $record) {
            return response()->json(['success' => false, 'message' => 'Fichaje no encontrado o no pertenece al usuario'], 404);
        }

        $requestId = DB::transaction(function () use ($authUser, $data): int {
            $requestId = $this->nextLegacyId('modification_requests');

            ModificationRequest::query()->create([
                'id' => $requestId,
                'record_id' => $data['recordId'],
                'employee_id' => $authUser->id,
                'new_date' => $data['newDate'],
                'new_time' => $data['newTime'] . ':00',
                'reason' => $data['reason'],
                'status' => 'pending',
            ]);

            $reviewerIds = $this->reviewerIdsForEmployee($authUser, (int) $authUser->id);
            $this->createNotifications(
                $reviewerIds,
                'modification_requested',
                'Nueva solicitud de modificacion',
                $authUser->name . ' ha solicitado cambiar su fichaje al ' . $data['newDate'] . ' a las ' . $data['newTime'] . '.',
                $requestId,
            );

            $this->createNotifications(
                [(int) $authUser->id],
                'modification_requested',
                'Solicitud de modificacion registrada',
                'Has solicitado cambiar tu fichaje al ' . $data['newDate'] . ' a las ' . $data['newTime'] . '.',
                $requestId,
            );

            return $requestId;
        });

        return response()->json([
            'success' => true,
            'message' => 'Solicitud creada',
            'request_id' => $requestId,
        ], 201);
    }

    public function approveRequest(Request $request, string $modificationRequest): JsonResponse
    {
        return $this->resolveRequestDecision($request, (int) $modificationRequest, true);
    }

    public function rejectRequest(Request $request, string $modificationRequest): JsonResponse
    {
        return $this->resolveRequestDecision($request, (int) $modificationRequest, false);
    }

    public function listConfirmations(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        return response()->json([
            'success' => true,
            'confirmations' => ModificationConfirmation::query()
                ->select([
                    'modification_confirmations.*',
                    'users.name as modified_by_name',
                    'records.timestamp as current_timestamp_record',
                ])
                ->join('users', 'users.id', '=', 'modification_confirmations.modified_by')
                ->join('records', 'records.id', '=', 'modification_confirmations.record_id')
                ->where('modification_confirmations.employee_id', $authUser->id)
                ->orderByDesc('modification_confirmations.created_at')
                ->get(),
        ]);
    }

    public function storeConfirmation(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $data = $request->validate([
            'recordId' => ['required', 'integer'],
            'newDate' => ['required', 'date'],
            'newTime' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string'],
            'source' => ['sometimes', 'string'],
        ]);

        $record = Record::query()
            ->select(['records.*', 'users.zone_id', 'users.role as employee_role'])
            ->join('users', 'users.id', '=', 'records.employee_id')
            ->where('records.id', $data['recordId'])
            ->first();

        if (! $record) {
            return response()->json(['success' => false, 'message' => 'Fichaje no encontrado'], 404);
        }

        if (! $this->canManageEmployee($authUser, (int) $record->employee_id, $record->employee_role, (int) $record->zone_id)) {
            return response()->json(['success' => false, 'message' => 'Solo puedes proponer cambios a empleados de tu zona'], 403);
        }

        $sourceInput = (string) ($data['source'] ?? 'gestión');
        $source = $sourceInput === 'gestion' ? 'gestión' : $sourceInput;
        if (! in_array($source, ['gestión', 'bolsa'], true)) {
            return response()->json(['success' => false, 'message' => 'Origen no válido'], 422);
        }

        $confirmationId = DB::transaction(function () use ($authUser, $data, $record, $source): int {
            $confirmationId = $this->nextLegacyId('modification_confirmations');

            ModificationConfirmation::query()->create([
                'id' => $confirmationId,
                'record_id' => $data['recordId'],
                'employee_id' => $record->employee_id,
                'new_date' => $data['newDate'],
                'new_time' => $data['newTime'] . ':00',
                'original_timestamp' => $record->timestamp,
                'modified_by' => $authUser->id,
                'reason' => $data['reason'],
                'source' => $source,
                'status' => 'pending',
            ]);

            $originText = $source === 'bolsa' ? 'desde la bolsa de horas' : 'desde gestion';
            $this->createNotifications(
                [(int) $record->employee_id],
                'modification_requested',
                'Propuesta de modificacion de fichaje',
                'El coordinador ha propuesto modificar tu fichaje del ' . $data['newDate'] . ' a las ' . $data['newTime'] . ' (' . $originText . '). Revisa Mis Solicitudes.',
                $confirmationId,
            );

            return $confirmationId;
        });

        return response()->json([
            'success' => true,
            'message' => 'Propuesta de modificación enviada al empleado',
            'confirmation_id' => $confirmationId,
        ], 201);
    }

    public function confirmConfirmation(Request $request, string $confirmation): JsonResponse
    {
        return $this->resolveConfirmationDecision($request, (int) $confirmation, true);
    }

    public function rejectConfirmation(Request $request, string $confirmation): JsonResponse
    {
        return $this->resolveConfirmationDecision($request, (int) $confirmation, false);
    }

    private function resolveRequestDecision(Request $request, int $requestId, bool $approve): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        if (! in_array($authUser->role, ['admin', 'coordinator'], true)) {
            return response()->json(['success' => false, 'message' => 'Solo coordinadores pueden aprobar'], 403);
        }

        $modificationRequest = ModificationRequest::query()
            ->select(['modification_requests.*', 'users.zone_id', 'users.role as employee_role'])
            ->join('users', 'users.id', '=', 'modification_requests.employee_id')
            ->where('modification_requests.id', $requestId)
            ->first();

        if (! $modificationRequest) {
            return response()->json(['success' => false, 'message' => 'Solicitud no encontrada'], 404);
        }

        if (! $this->canManageEmployee($authUser, (int) $modificationRequest->employee_id, $modificationRequest->employee_role, (int) $modificationRequest->zone_id)) {
            return response()->json(['success' => false, 'message' => $approve ? 'No puedes aprobar esta solicitud' : 'No puedes rechazar esta solicitud'], 403);
        }

        DB::transaction(function () use ($authUser, $modificationRequest, $approve): void {
            if ($approve) {
                Record::query()->where('id', $modificationRequest->record_id)->update([
                    'timestamp' => $this->composeTimestamp($modificationRequest->new_date, $modificationRequest->new_time),
                ]);
            }

            ModificationRequest::query()->where('id', $modificationRequest->id)->update([
                'status' => $approve ? 'approved' : 'rejected',
                'approved_by' => $authUser->id,
                'approved_at' => now(),
            ]);

            $this->createNotifications(
                [(int) $modificationRequest->employee_id],
                $approve ? 'modification_approved' : 'modification_rejected',
                $approve ? 'Modificacion aprobada' : 'Modificacion rechazada',
                $approve
                    ? 'Tu solicitud para cambiar el fichaje al ' . $this->formatDateValue($modificationRequest->new_date) . ' a las ' . substr((string) $modificationRequest->new_time, 0, 5) . ' ha sido aprobada y aplicada.'
                    : 'Tu solicitud para cambiar el fichaje al ' . $this->formatDateValue($modificationRequest->new_date) . ' a las ' . substr((string) $modificationRequest->new_time, 0, 5) . ' ha sido rechazada.',
                (int) $modificationRequest->id,
            );
        });

        return response()->json([
            'success' => true,
            'message' => $approve ? 'Solicitud aprobada y fichaje actualizado' : 'Solicitud rechazada',
        ]);
    }

    private function resolveConfirmationDecision(Request $request, int $confirmationId, bool $confirm): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $confirmation = ModificationConfirmation::query()
            ->where('id', $confirmationId)
            ->where('employee_id', $authUser->id)
            ->first();

        if (! $confirmation) {
            return response()->json(['success' => false, 'message' => 'Propuesta no encontrada'], 404);
        }

        DB::transaction(function () use ($confirmation, $confirm): void {
            if ($confirm) {
                Record::query()->where('id', $confirmation->record_id)->update([
                    'timestamp' => $this->composeTimestamp($confirmation->new_date, $confirmation->new_time),
                ]);
            }

            ModificationConfirmation::query()->where('id', $confirmation->id)->update([
                'status' => $confirm ? 'confirmed' : 'rejected',
                'confirmed_at' => now(),
            ]);

            $this->createNotifications(
                [(int) $confirmation->modified_by],
                $confirm ? 'modification_approved' : 'modification_rejected',
                $confirm ? 'Cambio confirmado por el empleado' : 'Cambio rechazado por el empleado',
                $confirm
                    ? 'El empleado ha confirmado la modificación de su fichaje al ' . $this->formatDateValue($confirmation->new_date) . ' a las ' . substr((string) $confirmation->new_time, 0, 5) . '.'
                    : 'El empleado ha rechazado la modificación propuesta para su fichaje del ' . $this->formatDateValue($confirmation->new_date) . ' a las ' . substr((string) $confirmation->new_time, 0, 5) . '.',
                (int) $confirmation->id,
            );
        });

        return response()->json([
            'success' => true,
            'message' => $confirm ? 'Cambio aprobado y fichaje actualizado' : 'Cambio rechazado, fichaje sin modificar',
        ]);
    }

    private function canManageEmployee(User $authUser, int $employeeId, ?string $employeeRole, int $zoneId): bool
    {
        if ($authUser->role === 'admin') {
            return true;
        }

        return $authUser->role === 'coordinator'
            && $employeeId !== (int) $authUser->id
            && $employeeRole === 'employee'
            && $zoneId === (int) $authUser->zone_id;
    }

    private function reviewerIdsForEmployee(User $employee, int $excludeUserId): array
    {
        $reviewerIds = User::query()
            ->where('active', 1)
            ->where('role', 'admin')
            ->where('id', '!=', $excludeUserId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($employee->role === 'employee' && $employee->zone_id) {
            $coordinatorIds = User::query()
                ->where('active', 1)
                ->where('role', 'coordinator')
                ->where('zone_id', $employee->zone_id)
                ->where('id', '!=', $excludeUserId)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $reviewerIds = array_merge($reviewerIds, $coordinatorIds);
        }

        return array_values(array_unique($reviewerIds));
    }

    private function createNotifications(array $userIds, string $type, string $title, string $message, ?int $relatedId = null): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        foreach ($userIds as $userId) {
            Notification::query()->create([
                'id' => $this->nextLegacyId('notifications'),
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'related_id' => $relatedId,
                'related_type' => 'modification',
                'is_read' => false,
            ]);
        }
    }

    private function composeTimestamp(mixed $date, mixed $time): string
    {
        return $this->formatDateValue($date) . ' ' . substr((string) $time, 0, 8);
    }

    private function formatDateValue(mixed $date): string
    {
        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
    }

    private function nextLegacyId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }
}
