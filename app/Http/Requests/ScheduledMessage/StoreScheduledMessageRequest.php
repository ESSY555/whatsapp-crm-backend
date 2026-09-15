<?php

namespace App\Http\Requests\ScheduledMessage;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduledMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = TenantManager::businessId();

        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)->whereNull('deleted_at')],
            'template_id' => ['nullable', 'integer', Rule::exists('message_templates', 'id')->where('business_id', $businessId)],
            'content' => ['required_without:template_id', 'nullable', 'string', 'max:4096'],
            'scheduled_at' => ['required', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required_without' => 'Content is required if no template is selected.',
            'scheduled_at.after' => 'Scheduled time must be in the future.',
        ];
    }
}
