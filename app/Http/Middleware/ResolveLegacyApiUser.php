<?php

namespace App\Http\Middleware;

use App\Support\LegacyApiAuth;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveLegacyApiUser
{
    public function __construct(private readonly LegacyApiAuth $legacyApiAuth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->legacyApiAuth->resolveAccessUserFromRequest($request);

        if (! $user) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Token no valido o expirado',
            ], 401);
        }

        $request->attributes->set('authUser', $user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}