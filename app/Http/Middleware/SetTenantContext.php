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
        if ($user = $request->user()) {
            $requestedBusinessId = $request->header('X-Business-Id');
            $businesses = $user->businesses;

            if ($businesses->isEmpty()) {
                return response()->json(['message' => 'No business access found.'], 403);
            }

            if ($requestedBusinessId) {
                if (!$businesses->contains('id', $requestedBusinessId)) {
                    return response()->json(['message' => 'Unauthorized for this business.'], 403);
                }
                $businessId = $requestedBusinessId;
            } else {
                $businessId = $businesses->first()->id;
            }

            app(TenantContext::class)->set($businessId);
        }

        return $next($request);
    }
}
