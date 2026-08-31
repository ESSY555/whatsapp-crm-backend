<?php

namespace App\Services;

use App\Models\Customer;
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
