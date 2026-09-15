<?php

namespace App\Http\Requests\WhatsApp;

use App\Models\MessageTemplate;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('message_templates', 'name')
                    ->where('business_id', TenantManager::businessId())
                    ->ignore($this->route('template')),
            ],
            'category' => ['nullable', 'string', 'max:255'],
            'language' => ['sometimes', 'string', 'max:10'],
            'content' => ['sometimes', 'required', 'string', 'min:1', 'max:4096'],
            'whatsapp_template_id' => ['nullable', 'string', 'max:255'],
            'variables' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::in(MessageTemplate::STATUSES)],
        ];
    }
}
