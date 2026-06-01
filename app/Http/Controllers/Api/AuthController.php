<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LegacyApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(private readonly LegacyApiAuth $legacyApiAuth)
    {
    }

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

        $tokens = $this->legacyApiAuth->issueTokenPair($user);

        return response()->json([
            'success' => true,
            'message' => 'Login exitoso',
            'token' => $tokens['access_token'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => $tokens['token_type'],
            'expires_in' => $tokens['access_expires_in'],
            'refresh_expires_in' => $tokens['refresh_expires_in'],
            'user' => $this->legacyApiAuth->serializeUser($user->loadMissing(['zone:id,name', 'calendar:id,name'])),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'refreshToken' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token requerido',
                'errors' => $validator->errors(),
            ], 422);
        }

        $session = $this->legacyApiAuth->refreshTokenPair($request->string('refreshToken')->toString());

        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token no valido o expirado',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sesion renovada',
            'token' => $session['access_token'],
            'access_token' => $session['access_token'],
            'refresh_token' => $session['refresh_token'],
            'token_type' => $session['token_type'],
            'expires_in' => $session['access_expires_in'],
            'refresh_expires_in' => $session['refresh_expires_in'],
            'user' => $this->legacyApiAuth->serializeUser($session['user']->loadMissing(['zone:id,name', 'calendar:id,name'])),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => $this->legacyApiAuth->serializeUser($user->loadMissing(['zone:id,name', 'calendar:id,name'])),
        ]);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'capabilities' => $this->legacyApiAuth->describeCapabilities($user->loadMissing(['zone:id,name', 'calendar:id,name'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'refreshToken' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token invalido',
                'errors' => $validator->errors(),
            ], 422);
        }

        $this->legacyApiAuth->revokeToken($request->bearerToken());

        if ($request->filled('refreshToken')) {
            $this->legacyApiAuth->revokeToken($request->string('refreshToken')->toString());
        }

        return response()->json([
            'success' => true,
            'message' => 'Logout exitoso',
        ]);
    }
}
