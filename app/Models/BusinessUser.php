<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BusinessUser extends Pivot
{
    protected $table = 'business_users';
    
    protected $fillable = [
        'business_id',
        'user_id',
        'role',
        'status',
    ];
}
