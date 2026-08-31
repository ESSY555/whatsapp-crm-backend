<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;

class EnsureBusinessRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        $businessId = TenantManager::businessId();
        $allowed = $user && $businessId && $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', $roles)
            ->exists();

        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
