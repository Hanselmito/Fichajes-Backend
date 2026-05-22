<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use JsonException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario y contraseña requeridos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::query()
            ->where('username', $request->string('username')->toString())
            ->where('active', true)
            ->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario o contraseña incorrectos',
            ], 401);
        }

        $token = $this->issueToken($user);

        return response()->json([
            'success' => true,
            'message' => 'Login exitoso',
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->resolveUserFromRequest($request);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Token no valido o expirado',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if (! $this->resolveUserFromRequest($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Token no valido o expirado',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Logout exitoso',
        ]);
    }

    private function serializeUser(User $user): array
    {
        return $user->withoutRelations()->toArray();
    }

    private function issueToken(User $user): string
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

    private function resolveUserFromRequest(Request $request): ?User
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
