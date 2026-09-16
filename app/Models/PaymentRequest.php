<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A bank-transfer receipt a business uploaded to pay for a plan. Approving
 * it creates the subscription; declining records the reason for the user.
 */
class PaymentRequest extends Model
{
    use BelongsToBusiness;

    public const STATUSES = ['pending', 'approved', 'declined'];

    protected $fillable = [
        'plan_id', 'submitted_by', 'amount', 'currency', 'receipt_path', 'receipt_name', 'receipt_mime',
        'reference', 'note', 'status', 'reviewed_by', 'reviewed_at', 'decline_reason',
    ];

    // The storage path is an implementation detail; clients download via the API.
    protected $hidden = ['receipt_path'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
