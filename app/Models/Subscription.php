<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use BelongsToBusiness;

    public const STATUSES = ['active', 'expired', 'cancelled'];

    protected $fillable = ['plan_id', 'payment_request_id', 'status', 'starts_at', 'ends_at', 'activated_by', 'note', 'expiry_notified_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'expiry_notified_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /** Subscriptions whose window covers this moment. */
    public function scopeCurrent($query)
    {
        return $query->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    /** Active subscriptions that start in the future (stacked renewals). */
    public function scopeUpcoming($query)
    {
        return $query->where('status', 'active')->where('starts_at', '>', now());
    }

    public function isCurrent(): bool
    {
        return $this->status === 'active' && $this->starts_at->isPast() && $this->ends_at->isFuture();
    }

    public function daysRemaining(): int
    {
        return max(0, (int) ceil(now()->diffInDays($this->ends_at, false)));
    }
}
