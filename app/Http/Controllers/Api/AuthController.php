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

        $token = $this->legacyApiAuth->issueToken($user);

        return response()->json([
            'success' => true,
            'message' => 'Login exitoso',
            'token' => $token,
            'user' => $this->legacyApiAuth->serializeUser($user->loadMissing(['zone:id,name', 'calendar:id,name'])),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Token no valido o expirado',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'user' => $this->legacyApiAuth->serializeUser($user->loadMissing(['zone:id,name', 'calendar:id,name'])),
        ]);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $user = $this->legacyApiAuth->resolveUserFromRequest($request);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Token no valido o expirado',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'capabilities' => $this->legacyApiAuth->describeCapabilities($user->loadMissing(['zone:id,name', 'calendar:id,name'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if (! $this->legacyApiAuth->resolveUserFromRequest($request)) {
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
}
