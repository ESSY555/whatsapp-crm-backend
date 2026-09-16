<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-cycle message counters by billing category. `sent_count` is
 * incremented on the WhatsApp *delivered* webhook (Meta only charges for
 * delivered messages), never when a message is queued or sent.
 */
class MessageUsage extends Model
{
    use BelongsToBusiness;

    protected $table = 'message_usage';

    public const CATEGORIES = ['utility', 'marketing', 'authentication', 'service'];

    protected $fillable = ['subscription_id', 'period_start', 'category', 'sent_count', 'failed_count'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'sent_count' => 'integer', 'failed_count' => 'integer'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Rows for the cycle a business is currently in. */
    public function scopeForCycle($query, Business $business, ?Subscription $subscription)
    {
        return $query->where('business_id', $business->id)
            ->where('period_start', static::periodStartFor($business, $subscription))
            ->when($subscription, fn ($q) => $q->where('subscription_id', $subscription->id), fn ($q) => $q->whereNull('subscription_id'));
    }

    /**
     * Start of the usage period a business is in: the current subscription's
     * cycle, otherwise the trial window (14-day trials never span a month).
     */
    public static function periodStartFor(Business $business, ?Subscription $subscription): Carbon
    {
        if ($subscription) {
            return $subscription->periodStart()->startOfDay();
        }

        $trialStart = $business->trial_ends_at
            ? $business->trial_ends_at->copy()->subDays((int) config('billing.trial_days', 14))
            : $business->created_at;

        return ($trialStart ?? now())->copy()->startOfDay();
    }
}
