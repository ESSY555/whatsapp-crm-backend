<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'invoice_id', 'product_id', 'description', 'quantity', 'unit_price',
        'tax_rate', 'tax_amount', 'subtotal', 'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2', 'total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
