<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use JsonException;

class LegacyApiAuth
{
    private const ACCESS_TOKEN_TYPE = 'access';

    private const REFRESH_TOKEN_TYPE = 'refresh';

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
        return $this->issueAccessToken($user);
    }

    public function issueAccessToken(User $user): string
    {
        return $this->issueSignedToken($user, self::ACCESS_TOKEN_TYPE, $this->accessTokenTtlMinutes())['token'];
    }

    public function issueTokenPair(User $user): array
    {
        $accessToken = $this->issueSignedToken($user, self::ACCESS_TOKEN_TYPE, $this->accessTokenTtlMinutes());
        $refreshToken = $this->issueSignedToken($user, self::REFRESH_TOKEN_TYPE, $this->refreshTokenTtlMinutes());

        $this->storeRefreshToken($refreshToken['token'], $refreshToken['payload']);

        return [
            'access_token' => $accessToken['token'],
            'access_expires_at' => (int) $accessToken['payload']['exp'],
            'access_expires_in' => max(0, (int) $accessToken['payload']['exp'] - now()->timestamp),
            'refresh_token' => $refreshToken['token'],
            'refresh_expires_at' => (int) $refreshToken['payload']['exp'],
            'refresh_expires_in' => max(0, (int) $refreshToken['payload']['exp'] - now()->timestamp),
            'token_type' => 'Bearer',
        ];
    }

    public function refreshTokenPair(string $refreshToken): ?array
    {
        $token = $this->normalizeToken($refreshToken);

        if (! $token) {
            return null;
        }

        $payload = $this->validatedPayloadFromToken($token, self::REFRESH_TOKEN_TYPE);

        if (! $payload || ! $this->isRefreshTokenActive($token, $payload)) {
            return null;
        }

        $user = $this->resolveUserFromPayload($payload);

        if (! $user) {
            $this->revokeToken($token);

            return null;
        }

        $this->revokeToken($token);

        return array_merge($this->issueTokenPair($user), [
            'user' => $user,
        ]);
    }

    public function resolveUserFromRequest(Request $request): ?User
    {
        return $this->resolveAccessUserFromRequest($request);
    }

    public function resolveAccessUserFromRequest(Request $request): ?User
    {
        return $this->resolveUserFromToken($request->bearerToken(), self::ACCESS_TOKEN_TYPE);
    }

    public function resolveUserFromToken(?string $token, string $expectedType = self::ACCESS_TOKEN_TYPE): ?User
    {
        $normalizedToken = $this->normalizeToken($token);

        if (! $normalizedToken) {
            return null;
        }

        $payload = $this->validatedPayloadFromToken($normalizedToken, $expectedType);

        if (! $payload) {
            return null;
        }

        return $this->resolveUserFromPayload($payload);
    }

    public function revokeToken(?string $token): void
    {
        $normalizedToken = $this->normalizeToken($token);

        if (! $normalizedToken) {
            return;
        }

        $payload = $this->payloadFromTokenWithoutRevocationCheck($normalizedToken);
        $ttlSeconds = max(60, (int) ($payload['exp'] ?? now()->addMinutes($this->refreshTokenTtlMinutes())->timestamp) - now()->timestamp);

        if (is_array($payload) && ($payload['typ'] ?? null) === self::REFRESH_TOKEN_TYPE) {
            $this->forgetRefreshToken($payload);
        }

        Cache::put($this->revokedTokenCacheKey($normalizedToken), true, now()->addSeconds($ttlSeconds));
    }

    public function assertSecretsAreConfigured(): void
    {
        $this->secretForTokenType(self::ACCESS_TOKEN_TYPE);
        $this->secretForTokenType(self::REFRESH_TOKEN_TYPE);
    }

    private function issueSignedToken(User $user, string $tokenType, int $ttlMinutes): array
    {
        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addMinutes($ttlMinutes);

        $payload = [
            'sub' => $user->id,
            'usr' => $user->username,
            'typ' => $tokenType,
            'jti' => (string) Str::uuid(),
            'iat' => $issuedAt->timestamp,
            'exp' => $expiresAt->timestamp,
            'sig' => sha1($this->userSignatureSeed($user)),
        ];

        try {
            $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            abort(500, 'No se pudo generar el token de autenticacion.');
        }

        $signature = hash_hmac('sha256', $encodedPayload, $this->secretForTokenType($tokenType));

        return [
            'token' => $encodedPayload.'.'.$signature,
            'payload' => $payload,
        ];
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

    private function accessTokenTtlMinutes(): int
    {
        return max(1, (int) config('auth.legacy_api.access_token_ttl_minutes', 15));
    }

    private function refreshTokenTtlMinutes(): int
    {
        return max(1, (int) config('auth.legacy_api.refresh_token_ttl_minutes', 10080));
    }

    private function isTokenRevoked(string $token): bool
    {
        return Cache::has($this->revokedTokenCacheKey($token));
    }

    private function revokedTokenCacheKey(string $token): string
    {
        return (string) config('auth.legacy_api.revoked_token_cache_prefix', 'legacy_api_revoked_token:').hash('sha256', $token);
    }

    private function refreshTokenCacheKey(array $payload): string
    {
        return (string) config('auth.legacy_api.refresh_token_cache_prefix', 'legacy_api_refresh_token:').($payload['jti'] ?? 'missing');
    }

    private function storeRefreshToken(string $token, array $payload): void
    {
        Cache::put(
            $this->refreshTokenCacheKey($payload),
            hash('sha256', $token),
            now()->addSeconds(max(60, (int) $payload['exp'] - now()->timestamp)),
        );
    }

    private function forgetRefreshToken(array $payload): void
    {
        Cache::forget($this->refreshTokenCacheKey($payload));
    }

    private function isRefreshTokenActive(string $token, array $payload): bool
    {
        $storedHash = Cache::get($this->refreshTokenCacheKey($payload));

        return is_string($storedHash) && hash_equals($storedHash, hash('sha256', $token));
    }

    private function normalizeToken(?string $token): ?string
    {
        if (! is_string($token)) {
            return null;
        }

        $normalizedToken = trim($token);

        return $normalizedToken !== '' && str_contains($normalizedToken, '.') ? $normalizedToken : null;
    }

    private function validatedPayloadFromToken(string $token, string $expectedType): ?array
    {
        if ($this->isTokenRevoked($token)) {
            return null;
        }

        $payload = $this->payloadFromTokenWithoutRevocationCheck($token);

        if (! is_array($payload) || ($payload['typ'] ?? null) !== $expectedType) {
            return null;
        }

        if (now()->timestamp >= (int) ($payload['exp'] ?? 0)) {
            return null;
        }

        return $payload;
    }

    private function payloadFromTokenWithoutRevocationCheck(string $token): ?array
    {
        [$encodedPayload, $providedSignature] = explode('.', $token, 2);
        $payload = $this->parseTokenPayload($encodedPayload);

        if (! is_array($payload)) {
            return null;
        }

        $tokenType = (string) ($payload['typ'] ?? '');

        if (! in_array($tokenType, [self::ACCESS_TOKEN_TYPE, self::REFRESH_TOKEN_TYPE], true)) {
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->secretForTokenType($tokenType));

        if (! hash_equals($expectedSignature, $providedSignature)) {
            return null;
        }

        return $payload;
    }

    private function resolveUserFromPayload(array $payload): ?User
    {
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

    private function secretForTokenType(string $tokenType): string
    {
        $configKey = $tokenType === self::REFRESH_TOKEN_TYPE ? 'refresh_token_secret' : 'access_token_secret';
        $secret = (string) config('auth.legacy_api.'.$configKey, '');

        if ($this->isStrictSecretValidationEnvironment()) {
            if ($secret === '' || str_starts_with($secret, 'change-this-local-') || strlen($secret) < 32) {
                throw new RuntimeException(sprintf('El secret de %s token debe configurarse explicitamente y tener al menos 32 caracteres fuera de local/testing.', $tokenType));
            }

            if ((string) config('auth.legacy_api.access_token_secret') === (string) config('auth.legacy_api.refresh_token_secret')) {
                throw new RuntimeException('AUTH_ACCESS_TOKEN_SECRET y AUTH_REFRESH_TOKEN_SECRET deben ser distintos fuera de local/testing.');
            }

            return $secret;
        }

        if ($secret !== '') {
            return $secret;
        }

        return (string) config('app.key', 'local-dev-secret');
    }

    private function isStrictSecretValidationEnvironment(): bool
    {
        return ! app()->environment(['local', 'testing']);
    }

    private function parseTokenPayload(string $encodedPayload): ?array
    {
        $payloadJson = $this->base64UrlDecode($encodedPayload);

        if ($payloadJson === false) {
            return null;
        }

        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
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