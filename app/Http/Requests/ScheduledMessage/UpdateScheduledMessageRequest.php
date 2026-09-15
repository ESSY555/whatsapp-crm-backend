<?php

namespace App\Http\Requests\ScheduledMessage;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScheduledMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => ['sometimes', 'date', 'after:now'],
            'content' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'template_id' => ['sometimes', 'nullable', 'integer', Rule::exists('message_templates', 'id')->where('business_id', TenantManager::businessId())],
        ];
    }
}
