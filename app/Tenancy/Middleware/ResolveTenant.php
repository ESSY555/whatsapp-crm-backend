<?php

namespace App\Tenancy\Middleware;

/**
 * Backwards-compatible alias for the single tenant middleware.
 * New routes should use App\Http\Middleware\SetTenantContext directly.
 */
class ResolveTenant extends \App\Http\Middleware\SetTenantContext
{
}
