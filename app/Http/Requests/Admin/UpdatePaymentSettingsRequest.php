<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_topup_amount' => ['sometimes', 'numeric', 'min:0'],
            'meta_utility_rate_usd' => ['sometimes', 'numeric', 'min:0.0001'],
            'usd_ngn_rate' => ['sometimes', 'numeric', 'min:1'],
            'markup_percent' => ['sometimes', 'numeric', 'min:0'],
            'vat_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'low_balance_threshold' => ['sometimes', 'numeric', 'min:0'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
        ];
    }
}
