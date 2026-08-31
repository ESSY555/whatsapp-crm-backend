<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerGroup extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['name', 'description'];

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_group_members')
            ->withTimestamps();
    }
}
