<?php

namespace App\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // In a real scenario, this would come from a centralized context/manager.
        // For now, we check the session or a custom header if we are in API context.
        if (request()->hasHeader('X-Business-ID')) {
            $builder->where('business_id', request()->header('X-Business-ID'));
        }
    }
}
