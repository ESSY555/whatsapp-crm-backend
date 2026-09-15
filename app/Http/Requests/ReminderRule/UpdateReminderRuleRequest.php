<?php

namespace App\Http\Requests\ReminderRule;

use App\Models\ReminderRule;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReminderRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'template_id' => ['sometimes', 'required', 'integer', Rule::exists('message_templates', 'id')->where('business_id', TenantManager::businessId())],
            'trigger_type' => ['sometimes', 'required', Rule::in(ReminderRule::TRIGGER_TYPES)],
            'days_offset' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
