<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One paid period. Lifecycle (driven by `subscriptions:expire` daily):
 *
 *   active  — starts_at <= now < ends_at, full access
 *   grace   — ends_at passed, full access until grace_ends_at + renewal banner
 *   expired — no access to outbound WhatsApp; CRM data stays readable
 *   cancelled — ended early by an admin
 */
class Subscription extends Model
{
    use BelongsToBusiness;

    public const STATUSES = ['active', 'grace', 'expired', 'cancelled'];

    protected $fillable = ['plan_id', 'payment_request_id', 'status', 'starts_at', 'ends_at', 'grace_ends_at', 'activated_by', 'note', 'expiry_notified_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'grace_ends_at' => 'datetime', 'expiry_notified_at' => 'datetime'];
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

    /** Subscriptions granting access right now (active period or grace). */
    public function scopeCurrent($query)
    {
        return $query->where('starts_at', '<=', now())->where(function ($q) {
            $q->where(fn ($a) => $a->where('status', 'active')->where('ends_at', '>', now()))
                ->orWhere(fn ($g) => $g->where('status', 'grace')->where('grace_ends_at', '>', now()));
        });
    }

    /** Active subscriptions that start in the future (stacked renewals). */
    public function scopeUpcoming($query)
    {
        return $query->where('status', 'active')->where('starts_at', '>', now());
    }

    public function isCurrent(): bool
    {
        if ($this->starts_at->isFuture()) {
            return false;
        }
        return ($this->status === 'active' && $this->ends_at->isFuture())
            || ($this->status === 'grace' && $this->grace_ends_at?->isFuture());
    }

    public function inGrace(): bool
    {
        return $this->status === 'grace';
    }

    public function daysRemaining(): int
    {
        return max(0, (int) ceil(now()->diffInDays($this->ends_at, false)));
    }

    /**
     * Start of the usage period that contains `$at`. Monthly plans have one
     * period; longer plans reset on each monthly anniversary of starts_at.
     */
    public function periodStart(?Carbon $at = null): Carbon
    {
        $at ??= now();
        $start = $this->starts_at->copy();
        if ($this->ends_at->diffInDays($this->starts_at, true) <= 31) {
            return $start;
        }
        while ($start->copy()->addMonthNoOverflow()->lte($at)) {
            $start->addMonthNoOverflow();
        }
        return $start;
    }
}
