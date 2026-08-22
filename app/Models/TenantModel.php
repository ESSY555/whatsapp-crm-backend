<?php

namespace App\Models;

use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->business_id) && request()->hasHeader('X-Business-ID')) {
                $model->business_id = request()->header('X-Business-ID');
            }
        });
    }
}
