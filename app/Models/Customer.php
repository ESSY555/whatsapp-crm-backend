<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\BelongsToBusiness;

class Customer extends Model
{
    use BelongsToBusiness;
    
    protected $fillable = ['name', 'email', 'phone', 'customer_group_id'];
}
