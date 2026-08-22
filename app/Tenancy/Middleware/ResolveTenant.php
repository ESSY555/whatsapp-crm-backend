<?php

namespace App\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $user = Auth::user();
        
        // Retrieve business ID from header primarily, fallback to route/query if strictly needed (but we strictly mandate header here for consistency)
        $requestedBusinessId = $request->header('X-Business-ID');

        if (!$requestedBusinessId) {
            return response()->json([
                'success' => false, 
                'message' => 'X-Business-ID header is required.'
            ], 400);
        }

        // Validate that this user is actually a member of this business
        $isMember = DB::table('business_users')
            ->where('business_id', $requestedBusinessId)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'success' => false, 
                'message' => 'You do not have permission to perform this action.'
            ], 403);
        }

        // Set the active business context safely
        TenantManager::set((int) $requestedBusinessId);

        $response = $next($request);

        // Clear context after request to prevent leakage in octane/long-running processes
        TenantManager::clear();

        return $response;
    }
}
