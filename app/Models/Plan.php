<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform-wide price tier. Plans are global (not tenant-scoped) and are
 * managed by the super admin.
 */
class Plan extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'price', 'currency', 'interval_days',
        'utility_quota', 'marketing_quota', 'allows_broadcasts', 'max_team_members',
        'is_trial_default', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'interval_days' => 'integer',
            'utility_quota' => 'integer',
            'marketing_quota' => 'integer',
            'max_team_members' => 'integer',
            'allows_broadcasts' => 'boolean',
            'is_trial_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function paymentRequestsExist(): bool
    {
        return $this->paymentRequests()->withoutGlobalScopes()->exists();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Quota for a billing category; null means unlimited.
     */
    public function quotaFor(string $category): ?int
    {
        return match ($category) {
            'utility' => $this->utility_quota,
            'marketing' => $this->marketing_quota,
            default => null,
        };
    }

    /**
     * The plan a trialing business runs on. Falls back to a conservative
     * in-memory plan so a fresh install without seeded plans still works.
     */
    public static function trialDefault(): self
    {
        return static::query()->where('is_trial_default', true)->first()
            ?? static::query()->where('code', 'starter')->first()
            ?? new static([
                'code' => 'trial', 'name' => 'Free trial', 'price' => 0, 'currency' => 'NGN', 'interval_days' => 14,
                'utility_quota' => 200, 'marketing_quota' => 0, 'allows_broadcasts' => false, 'max_team_members' => 2,
            ]);
    }
}
