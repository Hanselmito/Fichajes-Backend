<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LegacyApiAuth;
use App\Support\LegacyScheduleHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleHistoryController extends Controller
{
    public function __construct(
        private readonly LegacyApiAuth $legacyApiAuth,
        private readonly LegacyScheduleHistory $scheduleHistory,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $this->legacyApiAuth->resolveUserFromRequest($request);
        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        $employeeId = $request->integer('employeeId', (int) $authUser->id);
        if (! $this->canAccessEmployee($authUser, $employeeId)) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $referenceDate = $request->date('date')?->toDateString() ?? now()->toDateString();

        return response()->json([
            'success' => true,
            'employee_id' => $employeeId,
            'date' => $referenceDate,
            'versions' => $this->scheduleHistory->listVersions($employeeId),
            'current' => $this->scheduleHistory->getVersionForDate($employeeId, $referenceDate),
        ]);
    }

    private function canAccessEmployee(User $authUser, int $employeeId): bool
    {
        if ($employeeId === (int) $authUser->id || $authUser->role === 'admin') {
            return true;
        }

        if ($authUser->role !== 'coordinator') {
            return false;
        }

        $targetUser = User::query()->select(['id', 'role', 'zone_id'])->find($employeeId);

        return $targetUser !== null
            && $targetUser->role === 'employee'
            && (int) $targetUser->zone_id === (int) $authUser->zone_id;
    }
}