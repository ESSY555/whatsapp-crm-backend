<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;

    protected $fillable = [
        'wallet_id',
        'business_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'price_per_message',
        'reference',
        'description',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'price_per_message' => 'decimal:2',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
