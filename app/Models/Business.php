<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'logo', 'email', 'phone', 'address',
        'city', 'state', 'country', 'currency', 'timezone',
        'tax_number', 'status'
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_users')
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    public function settings(): HasMany
    {
        return $this->hasMany(BusinessSetting::class);
    }
}
