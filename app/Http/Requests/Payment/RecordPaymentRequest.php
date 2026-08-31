<?php

namespace App\Http\Requests\Payment;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('business_id', TenantManager::businessId())],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'mobile_money', 'other'])],
            'reference' => ['nullable', 'string', 'max:255', Rule::unique('payments', 'reference')->where('business_id', TenantManager::businessId())],
            'payment_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
