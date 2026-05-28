<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use JsonException;

class LegacyApiAuth
{
    private const PERMISSION_LABELS = [
        'can_view_reports' => 'Puede acceder a reportes fuera de su ambito minimo',
        'can_view_all_records' => 'Puede consultar fichajes fuera de su ambito minimo',
        'can_view_all_bolsa' => 'Puede consultar bolsa de horas fuera de su ambito minimo',
        'can_view_all_dashboard' => 'Puede consultar dashboard fuera de su ambito minimo',
        'can_view_user_overview' => 'Puede consultar el resumen global de empleados',
        'can_view_coordinators_in_employee_view' => 'Puede incluir coordinadores en la vista de empleados',
        'can_view_all_vacations' => 'Puede consultar vacaciones fuera de su ambito minimo',
        'can_promote_to_coordinator' => 'Puede promover empleados a coordinador',
    ];

    private const SCOPED_PERMISSION_MAP = [
        'can_view_reports' => 'can_view_reports_zone_ids',
        'can_view_all_records' => 'can_view_all_records_zone_ids',
        'can_view_all_bolsa' => 'can_view_all_bolsa_zone_ids',
        'can_view_all_dashboard' => 'can_view_all_dashboard_zone_ids',
        'can_view_user_overview' => 'can_view_user_overview_zone_ids',
        'can_view_coordinators_in_employee_view' => 'can_view_coordinators_in_employee_view_zone_ids',
        'can_view_all_vacations' => 'can_view_all_vacations_zone_ids',
    ];

    public function issueToken(User $user): string
    {
        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addMinutes($this->tokenTtlMinutes());

        $payload = [
            'sub' => $user->id,
            'usr' => $user->username,
            'iat' => $issuedAt->timestamp,
            'exp' => $expiresAt->timestamp,
            'sig' => sha1($this->userSignatureSeed($user)),
        ];

        try {
            $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            abort(500, 'No se pudo generar el token de autenticacion.');
        }

        $signature = hash_hmac('sha256', $encodedPayload, $this->tokenSecret());

        return $encodedPayload.'.'.$signature;
    }

    public function resolveUserFromRequest(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (! $token || ! str_contains($token, '.')) {
            return null;
        }

        [$encodedPayload, $providedSignature] = explode('.', $token, 2);
        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->tokenSecret());

        if (! hash_equals($expectedSignature, $providedSignature)) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($payloadJson === false) {
            return null;
        }

        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload) || now()->timestamp >= (int) ($payload['exp'] ?? 0)) {
            return null;
        }

        $user = User::query()
            ->whereKey((int) ($payload['sub'] ?? 0))
            ->where('active', true)
            ->first();

        if (! $user) {
            return null;
        }

        if (! hash_equals((string) ($payload['sig'] ?? ''), sha1($this->userSignatureSeed($user)))) {
            return null;
        }

        return $user;
    }

    public function serializeUser(User $user): array
    {
        $data = array_merge([
            'zone_name' => null,
            'calendar_name' => null,
            'can_view_reports' => false,
            'can_view_all_records' => false,
            'can_view_all_bolsa' => false,
            'can_view_all_dashboard' => false,
            'can_view_user_overview' => false,
            'can_view_coordinators_in_employee_view' => false,
            'can_view_all_vacations' => false,
            'can_promote_to_coordinator' => false,
            'can_view_reports_zone_ids' => null,
            'can_view_all_records_zone_ids' => null,
            'can_view_all_bolsa_zone_ids' => null,
            'can_view_all_dashboard_zone_ids' => null,
            'can_view_user_overview_zone_ids' => null,
            'can_view_coordinators_in_employee_view_zone_ids' => null,
            'can_view_all_vacations_zone_ids' => null,
        ], $user->withoutRelations()->toArray());

        $data['zone_name'] = $user->zone?->name;
        $data['calendar_name'] = $user->calendar?->name;

        return $data;
    }

    public function describeCapabilities(User $user): array
    {
        $role = (string) $user->role;

        return [
            'user' => [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'name' => (string) $user->name,
                'role' => $role,
                'zone_id' => $user->zone_id !== null ? (int) $user->zone_id : null,
            ],
            'navigation' => [
                'dashboard' => true,
                'records' => true,
                'work_hours' => true,
                'notifications' => true,
                'breaks' => true,
                'modifications' => true,
                'vacation_requests' => true,
                'vacations' => true,
                'reports' => $this->userHasAccess($user, 'can_view_reports'),
                'user_overview' => $this->userHasAccess($user, 'can_view_user_overview'),
                'zones' => in_array($role, ['admin', 'coordinator'], true),
                'users' => in_array($role, ['admin', 'coordinator'], true),
                'clients' => in_array($role, ['admin', 'coordinator'], true),
                'quadrants' => in_array($role, ['admin', 'coordinator'], true),
                'schedules' => in_array($role, ['admin', 'coordinator'], true),
                'employee_schedules' => in_array($role, ['admin', 'coordinator'], true),
                'services' => in_array($role, ['admin', 'coordinator'], true),
                'calendars' => in_array($role, ['admin', 'coordinator'], true),
                'zone_holidays' => in_array($role, ['admin', 'coordinator'], true),
                'tolerance' => in_array($role, ['admin', 'coordinator'], true),
                'bolsa_anotaciones' => true,
            ],
            'resource_access' => [
                'users' => $this->resourceAccess(in_array($role, ['admin', 'coordinator'], true), $role === 'admin'),
                'zones' => $this->resourceAccess(in_array($role, ['admin', 'coordinator'], true), $role === 'admin'),
                'clients' => $this->resourceAccess(in_array($role, ['admin', 'coordinator'], true), in_array($role, ['admin', 'coordinator'], true)),
                'records' => $this->resourceAccess(true, true, $this->getAccessibleZoneScope($user, 'can_view_all_records')),
                'reports' => $this->resourceAccess($this->userHasAccess($user, 'can_view_reports'), false, $this->getAccessibleZoneScope($user, 'can_view_reports')),
                'dashboard' => $this->resourceAccess(true, false, $this->getAccessibleZoneScope($user, 'can_view_all_dashboard')),
                'bolsa_anotaciones' => $this->resourceAccess(true, true, $this->getAccessibleZoneScope($user, 'can_view_all_bolsa')),
                'vacations' => $this->resourceAccess(true, in_array($role, ['admin', 'coordinator'], true), $this->getAccessibleZoneScope($user, 'can_view_all_vacations')),
                'vacation_requests' => $this->resourceAccess(true, in_array($role, ['admin', 'coordinator'], true), $this->getAccessibleZoneScope($user, 'can_view_all_vacations')),
                'quadrants' => $this->resourceAccess(in_array($role, ['admin', 'coordinator'], true), in_array($role, ['admin', 'coordinator'], true)),
                'schedules' => $this->resourceAccess(true, in_array($role, ['admin', 'coordinator'], true)),
                'employee_schedules' => $this->resourceAccess(in_array($role, ['admin', 'coordinator'], true), in_array($role, ['admin', 'coordinator'], true)),
                'services' => $this->resourceAccess(in_array($role, ['admin', 'coordinator'], true), in_array($role, ['admin', 'coordinator'], true)),
                'notifications' => $this->resourceAccess(true, true),
                'breaks' => $this->resourceAccess(true, true),
                'modifications' => $this->resourceAccess(true, true),
                'calendars' => $this->resourceAccess(in_array($role, ['admin', 'coordinator'], true), in_array($role, ['admin', 'coordinator'], true)),
                'zone_holidays' => $this->resourceAccess(in_array($role, ['admin', 'coordinator'], true), in_array($role, ['admin', 'coordinator'], true)),
                'tolerance' => $this->resourceAccess(in_array($role, ['admin', 'coordinator'], true), in_array($role, ['admin', 'coordinator'], true)),
                'qr_generator' => $this->resourceAccess(true, false),
            ],
            'permissions' => $this->permissionDetails($user),
        ];
    }

    public function userHasAccess(User $user, string $permission): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($this->getScopedAccessZoneIds($user, $permission) !== []) {
            return true;
        }

        return (bool) ($user->{$permission} ?? false);
    }

    public function getAccessibleZoneScope(User $user, string $permission, bool $includeOwnZone = true): ?array
    {
        if ($user->role === 'admin') {
            return null;
        }

        $zoneIds = $this->getScopedAccessZoneIds($user, $permission);
        $ownZoneId = (int) ($user->zone_id ?? 0);

        if ($includeOwnZone && $ownZoneId > 0) {
            $zoneIds[] = $ownZoneId;
        }

        $zoneIds = $this->normalizeZoneIdList($zoneIds);

        if ($zoneIds !== []) {
            return $zoneIds;
        }

        if ((bool) ($user->{$permission} ?? false)) {
            return null;
        }

        return $ownZoneId > 0 ? [$ownZoneId] : [];
    }

    public function normalizeZoneIdList(mixed $value): array
    {
        if (is_array($value)) {
            $zoneIds = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                $zoneIds = is_array($decoded) ? $decoded : explode(',', $value);
            } catch (JsonException) {
                $zoneIds = explode(',', $value);
            }
        } else {
            $zoneIds = [];
        }

        $zoneIds = array_values(array_unique(array_filter(array_map(static fn ($zoneId): int => (int) $zoneId, $zoneIds), static fn (int $zoneId): bool => $zoneId > 0)));
        sort($zoneIds);

        return $zoneIds;
    }

    private function getScopedAccessZoneIds(User $user, string $permission): array
    {
        $column = self::SCOPED_PERMISSION_MAP[$permission] ?? null;

        if (! $column) {
            return [];
        }

        return $this->normalizeZoneIdList($user->{$column} ?? []);
    }

    private function permissionDetails(User $user): array
    {
        $details = [];

        foreach (self::PERMISSION_LABELS as $permission => $label) {
            $details[$permission] = [
                'label' => $label,
                'allowed' => $this->userHasAccess($user, $permission),
                'scoped_zone_ids' => $this->getScopedAccessZoneIds($user, $permission),
                'effective_zone_scope' => $this->getAccessibleZoneScope($user, $permission),
            ];
        }

        return $details;
    }

    private function resourceAccess(bool $visible, bool $manage, ?array $zoneScope = null): array
    {
        return [
            'visible' => $visible,
            'manage' => $manage,
            'zone_scope' => $zoneScope,
        ];
    }

    private function userSignatureSeed(User $user): string
    {
        return implode('|', [
            $user->id,
            $user->password_hash,
            optional($user->updated_at)->timestamp,
            $user->active ? '1' : '0',
        ]);
    }

    private function tokenSecret(): string
    {
        return (string) (config('app.key') ?: env('AUTH_TOKEN_SECRET', 'change-this-local-auth-secret'));
    }

    private function tokenTtlMinutes(): int
    {
        return max(1, (int) env('AUTH_TOKEN_TTL_MINUTES', 10080));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}