<?php

namespace App\Jobs;

use App\Models\Business;
use App\Services\ReminderService;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs the reminder engine for one business (or every active business when
 * no id is given, which is what the daily scheduler does).
 */
class ProcessPaymentReminders implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public ?int $businessId = null)
    {
    }

    public function handle(ReminderService $reminders): void
    {
        if ($this->businessId !== null) {
            $this->runFor($this->businessId, $reminders);
            return;
        }

        Business::query()->whereIn('status', ['active', 'trial'])->pluck('id')
            ->each(fn (int $id) => self::dispatch($id));
    }

    private function runFor(int $businessId, ReminderService $reminders): void
    {
        $summary = TenantManager::runAs($businessId, fn () => $reminders->run());
        Log::info("Payment reminders processed for business {$businessId}", $summary);
    }
}
