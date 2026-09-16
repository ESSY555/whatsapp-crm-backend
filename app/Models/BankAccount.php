<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where businesses send bank transfers for their plan. Global, managed by
 * the super admin.
 */
class BankAccount extends Model
{
    protected $fillable = ['bank_name', 'account_name', 'account_number', 'currency', 'instructions', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
