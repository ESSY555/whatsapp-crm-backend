<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToBusiness;

class BusinessSetting extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['key', 'value'];
}
