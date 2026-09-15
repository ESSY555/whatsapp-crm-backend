<?php

namespace App\Http\Requests\WhatsApp;

use App\Models\MessageTemplate;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('message_templates', 'name')->where('business_id', TenantManager::businessId())],
            'category' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:10'],
            'content' => ['required', 'string', 'min:1', 'max:4096'],
            'whatsapp_template_id' => ['nullable', 'string', 'max:255'],
            'variables' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(MessageTemplate::STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'language' => $this->input('language') ?: 'en',
            'status' => $this->input('status') ?: 'draft',
        ]);
    }
}
