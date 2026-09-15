<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPaymentReminders;
use App\Models\Business;
use App\Services\ReminderService;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

class ProcessRemindersCommand extends Command
{
    protected $signature = 'reminders:process {--business= : Only run for one business id} {--sync : Run inline instead of queueing}';

    protected $description = 'Queue automated payment reminders for every active business';

    public function handle(ReminderService $reminders): int
    {
        $businessIds = $this->option('business')
            ? [(int) $this->option('business')]
            : Business::query()->whereIn('status', ['active', 'trial'])->pluck('id')->all();

        foreach ($businessIds as $id) {
            if ($this->option('sync')) {
                $summary = TenantManager::runAs($id, fn () => $reminders->run());
                $this->info("Business {$id}: " . json_encode($summary));
            } else {
                ProcessPaymentReminders::dispatch($id);
            }
        }

        $this->info(sprintf('%d business(es) processed.', count($businessIds)));

        return self::SUCCESS;
    }
}
