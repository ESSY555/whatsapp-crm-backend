<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'customer_id', 'invoice_id', 'amount', 'payment_method', 'reference', 'payment_date',
        'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date'];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
