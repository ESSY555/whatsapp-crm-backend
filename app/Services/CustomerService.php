<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function create(array $attributes): Customer
    {
        return DB::transaction(function () use ($attributes) {
            $groupIds = $attributes['group_ids'] ?? [];
            unset($attributes['group_ids']);

            $customer = Customer::create($attributes);
            $this->syncGroups($customer, $groupIds);

            return $customer->load('groups');
        });
    }

    public function update(Customer $customer, array $attributes): Customer
    {
        return DB::transaction(function () use ($customer, $attributes) {
            $hasGroups = array_key_exists('group_ids', $attributes);
            $groupIds = $attributes['group_ids'] ?? [];
            unset($attributes['group_ids']);

            $customer->update($attributes);
            if ($hasGroups) {
                $this->syncGroups($customer, $groupIds);
            }

            return $customer->refresh()->load('groups');
        });
    }

    /**
     * Financial summary derived from invoices and payments (never stored).
     */
    public function stats(Customer $customer): array
    {
        $invoices = $customer->invoices()->where('status', '!=', 'cancelled');

        return [
            'invoices_count' => (clone $invoices)->count(),
            'total_invoiced' => round((float) (clone $invoices)->where('status', '!=', 'draft')->sum('total'), 2),
            'total_paid' => round((float) $customer->payments()->sum('amount'), 2),
            'outstanding_balance' => round((float) (clone $invoices)->whereIn('status', Invoice::OPEN_STATUSES)->sum('balance_due'), 2),
            'overdue_balance' => round((float) (clone $invoices)->where('status', 'overdue')->sum('balance_due'), 2),
            'last_invoice_at' => (clone $invoices)->max('issue_date'),
            'last_payment_at' => $customer->payments()->completed()->max('payment_date'),
        ];
    }

    private function syncGroups(Customer $customer, array $groupIds): void
    {
        $validGroupIds = $customer->groups()->getRelated()::query()
            ->whereIn('id', $groupIds)
            ->pluck('id')
            ->all();

        $customer->groups()->syncWithPivotValues($validGroupIds, [
            'business_id' => TenantManager::businessId(),
        ]);
    }
}
