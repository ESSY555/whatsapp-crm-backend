<?php

namespace App\Http\Requests\Broadcast;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddRecipientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = TenantManager::businessId();

        return [
            'customer_ids' => ['nullable', 'array'],
            'customer_ids.*' => ['integer', Rule::exists('customers', 'id')->where('business_id', $businessId)->whereNull('deleted_at')],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', Rule::exists('customer_groups', 'id')->where('business_id', $businessId)],
            'all_customers' => ['nullable', 'boolean'],
        ];
    }
}
