<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'balance',
        'currency',
        'low_balance_alerted',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'low_balance_alerted' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->orderBy('id', 'desc');
    }
}
