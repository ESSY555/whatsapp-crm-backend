<?php

namespace App\Http\Requests\Broadcast;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = TenantManager::businessId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'template_id' => ['nullable', 'integer', Rule::exists('message_templates', 'id')->where('business_id', $businessId)],
            'content' => ['required_without:template_id', 'nullable', 'string', 'max:4096'],
            'customer_ids' => ['nullable', 'array'],
            'customer_ids.*' => ['integer', Rule::exists('customers', 'id')->where('business_id', $businessId)->whereNull('deleted_at')],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', Rule::exists('customer_groups', 'id')->where('business_id', $businessId)],
            'all_customers' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'send_now' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required_without' => 'Type a message or choose a template.',
            'scheduled_at.after' => 'The scheduled time must be in the future.',
        ];
    }
}
