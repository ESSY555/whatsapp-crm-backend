<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Price tiers from the cost model (2026-09): each plan clears ~45% gross
 * margin assuming every included message is Utility-category (~₦10.05 at
 * $0.0067 and ₦1,500/$). Marketing messages cost 7–8x more, so they are
 * NOT included in any plan (marketing_quota 0, broadcasts off); a later
 * per-category allowance only needs the two columns flipped in the admin UI.
 *
 * Re-running updates price/quota to the model's values but never re-enables
 * a plan the admin deactivated.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'Invoices, payment reminders and receipts over WhatsApp for a small business.',
                'price' => 15000,
                'utility_quota' => 522,
                'max_team_members' => 2,
                'is_trial_default' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'business',
                'name' => 'Business',
                'description' => 'More monthly messages and a bigger team for a growing business.',
                'price' => 30000,
                'utility_quota' => 1343,
                'max_team_members' => 5,
                'is_trial_default' => false,
                'sort_order' => 2,
            ],
            [
                'code' => 'professional',
                'name' => 'Professional',
                'description' => 'High-volume invoicing and reminders across a large customer base.',
                'price' => 60000,
                'utility_quota' => 2985,
                'max_team_members' => 15,
                'is_trial_default' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['code' => $plan['code']], $plan + [
                'currency' => 'NGN',
                'interval_days' => 30,
                'marketing_quota' => 0,
                'allows_broadcasts' => false,
            ]);
        }

        // The pre-cost-model "pro" tier is superseded by Professional.
        Plan::query()->where('code', 'pro')->update(['is_active' => false, 'is_trial_default' => false]);
    }
}
