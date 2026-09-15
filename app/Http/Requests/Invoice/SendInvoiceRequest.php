<?php

namespace App\Http\Requests\Invoice;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'via_whatsapp' => ['sometimes', 'boolean'],
            'message' => ['nullable', 'string', 'max:4096'],
            'template_id' => ['nullable', 'integer', Rule::exists('message_templates', 'id')->where('business_id', TenantManager::businessId())],
        ];
    }
}
