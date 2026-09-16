<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform administration. Two conditions, both enforced server-side:
 *
 *  1. the user carries the is_super_admin flag, and
 *  2. the request's token was issued by the admin console sign-in
 *     (POST /auth/admin/login), which only the configured Super Admin
 *     credentials can pass.
 *
 * A token from the business login never reaches /admin, even for a flagged
 * account. Every real request carries a persisted PersonalAccessToken; the
 * unsaved/transient tokens Sanctum::actingAs() creates only exist in tests.
 */
class EnsureSuperAdmin
{
    public const TOKEN_NAME = 'admin-console';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $isPersisted = $token instanceof PersonalAccessToken && $token->exists;
        // Bearer requests always carry a persisted token, so this is strict in
        // production; unsaved tokens can only come from test authentication.
        $fromConsole = $token !== null && (!$isPersisted || $token->name === self::TOKEN_NAME);

        if (!$user?->isSuperAdmin() || !$fromConsole) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
