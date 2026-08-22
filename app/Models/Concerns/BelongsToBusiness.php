<?php

namespace App\Models\Concerns;

use App\Tenancy\Scopes\BusinessScope;
use App\Tenancy\TenantManager;
use App\Models\Business;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToBusiness
{
    /**
     * Boot the trait to apply scope and auto-assign business_id.
     */
    protected static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function ($model) {
            if (empty($model->business_id) && TenantManager::has()) {
                $model->business_id = TenantManager::businessId();
            }
        });
    }

    /**
     * Relationship to Business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }
}
