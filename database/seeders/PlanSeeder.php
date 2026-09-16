<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Default price tiers. Safe to re-run: existing plans are matched by code
 * and only created when missing, so admin edits are never overwritten.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'Invoices, reminders and receipts over WhatsApp for a small business.',
                'price' => 5000,
                'currency' => 'NGN',
                'interval_days' => 30,
                'utility_quota' => 500,
                'marketing_quota' => 0,
                'allows_broadcasts' => false,
                'max_team_members' => 2,
                'is_trial_default' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'business',
                'name' => 'Business',
                'description' => 'Everything in Starter plus broadcasts to your customer list.',
                'price' => 15000,
                'currency' => 'NGN',
                'interval_days' => 30,
                'utility_quota' => 3000,
                'marketing_quota' => 1000,
                'allows_broadcasts' => true,
                'max_team_members' => 5,
                'is_trial_default' => false,
                'sort_order' => 2,
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'description' => 'High-volume messaging and a bigger team.',
                'price' => 40000,
                'currency' => 'NGN',
                'interval_days' => 30,
                'utility_quota' => 15000,
                'marketing_quota' => 5000,
                'allows_broadcasts' => true,
                'max_team_members' => 15,
                'is_trial_default' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->firstOrCreate(['code' => $plan['code']], $plan + ['is_active' => true]);
        }
    }
}
