<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToBusiness;

    public const METHODS = ['cash', 'bank_transfer', 'card', 'mobile_money', 'other'];

    protected $fillable = [
        'customer_id', 'invoice_id', 'amount', 'payment_method', 'reference', 'payment_date',
        'notes', 'recorded_by', 'status', 'reversal_of_id', 'reversed_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date', 'reversed_at' => 'datetime'];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function reversalOf(): BelongsTo { return $this->belongsTo(Payment::class, 'reversal_of_id'); }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
