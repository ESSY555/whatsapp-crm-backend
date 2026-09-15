<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\InvoiceService;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

class MarkOverdueInvoicesCommand extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Flip unpaid invoices past their due date to overdue and notify the business';

    public function handle(InvoiceService $invoices): int
    {
        $total = 0;

        Business::query()->pluck('id')->each(function (int $businessId) use ($invoices, &$total) {
            $total += TenantManager::runAs($businessId, fn () => $invoices->markOverdue())->count();
        });

        $this->info("{$total} invoice(s) marked overdue.");

        return self::SUCCESS;
    }
}
