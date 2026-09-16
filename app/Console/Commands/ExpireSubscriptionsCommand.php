<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Expire ended subscriptions and trials, and warn businesses whose plan ends within a week';

    public function handle(BillingService $billing): int
    {
        [$expired, $notified] = $billing->runExpiry();

        $this->info("Expired {$expired} subscription(s); sent {$notified} renewal reminder(s).");

        return self::SUCCESS;
    }
}
