<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

use App\Models\Concerns\BelongsToBusiness;

class Customer extends Model
{
    use BelongsToBusiness, SoftDeletes;
    
    protected $fillable = [
        'name', 'business_name', 'phone', 'email', 'address', 'category',
        'notes', 'status',
    ];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'customer_group_members')
            ->withTimestamps();
    }
}
