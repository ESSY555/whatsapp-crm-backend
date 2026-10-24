<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSetting extends Model
{
    protected $table = 'payment_settings';

    protected $fillable = [
        'min_topup_amount',
        'meta_utility_rate_usd',
        'usd_ngn_rate',
        'markup_percent',
        'vat_percent',
        'low_balance_threshold',
        'bank_account_id',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'min_topup_amount' => 'decimal:2',
            'meta_utility_rate_usd' => 'decimal:4',
            'usd_ngn_rate' => 'decimal:2',
            'markup_percent' => 'decimal:2',
            'vat_percent' => 'decimal:2',
            'low_balance_threshold' => 'decimal:2',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get or create the singleton platform payment setting record.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'min_topup_amount' => 10000.00,
                'meta_utility_rate_usd' => 0.0101,
                'usd_ngn_rate' => 1500.00,
                'markup_percent' => 100.00,
                'vat_percent' => 0.00,
                'low_balance_threshold' => 200.00,
            ]
        );
    }

    /**
     * Compute price per message for a category using bcmath.
     * Currently utility category is supported.
     *
     * TODO: Add rate settings for 'marketing' and 'authentication' template categories
     * when they are enabled for wallet billing.
     */
    public function calculatePricePerMessage(string $category = 'utility'): float
    {
        // Scale 6 for internal precision during intermediate arithmetic
        $metaRate = (string) $this->meta_utility_rate_usd;
        $fxRate = (string) $this->usd_ngn_rate;
        $vatPct = (string) $this->vat_percent;
        $markupPct = (string) $this->markup_percent;

        // base_cost_usd = meta_utility_rate_usd
        // meta_cost_ngn = meta_utility_rate_usd * usd_ngn_rate
        $metaCostNgn = bcmul($metaRate, $fxRate, 6);

        // If vat_percent > 0: cost_after_vat = meta_cost_ngn * (1 + vat / 100)
        if (bccomp($vatPct, '0', 4) > 0) {
            $vatMultiplier = bcadd('1', bcdiv($vatPct, '100', 6), 6);
            $baseCost = bcmul($metaCostNgn, $vatMultiplier, 6);
        } else {
            $baseCost = $metaCostNgn;
        }

        // price_per_message = baseCost * (1 + markup / 100)
        $markupMultiplier = bcadd('1', bcdiv($markupPct, '100', 6), 6);
        $rawPrice = bcmul($baseCost, $markupMultiplier, 6);

        // Round to 2 decimals
        return round((float) $rawPrice, 2);
    }

    /**
     * Compute estimate of messages for a given top-up amount.
     */
    public function calculateMessagesFor(float $amount, string $category = 'utility'): int
    {
        $price = $this->calculatePricePerMessage($category);
        if ($price <= 0) {
            return 0;
        }

        return (int) floor($amount / $price);
    }
}
