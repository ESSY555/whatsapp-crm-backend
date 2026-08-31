<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Tenancy\TenantContext;

class SetTenantContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $businesses = $user?->businesses()->wherePivot('status', 'active')->get();

        if (!$user || $businesses->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No active business access found.',
            ], 403);
        }

        // A header may select an already-authorized membership. It never grants
        // access and request payload/query business_id values are ignored.
        $requestedBusinessId = $request->header('X-Business-ID');
        $business = $requestedBusinessId
            ? $businesses->firstWhere('id', (int) $requestedBusinessId)
            : $businesses->first();

        if (!$business) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this business.',
            ], 403);
        }

        $context = app(TenantContext::class);
        $context->set($business->id);

        try {
            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
