<?php

namespace App\Http\Requests\WhatsApp;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = TenantManager::businessId();

        return [
            'conversation_id' => ['required_without:customer_id', 'nullable', 'integer', Rule::exists('conversations', 'id')->where('business_id', $businessId)],
            'customer_id' => ['required_without:conversation_id', 'nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)->whereNull('deleted_at')],
            'body' => ['required_without:template_id', 'nullable', 'string', 'min:1', 'max:4096'],
            'template_id' => ['nullable', 'integer', Rule::exists('message_templates', 'id')->where('business_id', $businessId)],
            'template_variables' => ['nullable', 'array'],
            'template_variables.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'conversation_id.required_without' => 'A conversation or customer is required to send a message.',
            'customer_id.required_without' => 'A conversation or customer is required to send a message.',
            'body.required_without' => 'Message body is required when no template is selected.',
            'template_id.exists' => 'The selected template does not exist.',
        ];
    }
}
