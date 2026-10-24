<?php

namespace App\Http\Requests\Wallet;

use App\Models\PaymentSetting;
use Illuminate\Foundation\Http\FormRequest;

class SubmitTopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $setting = PaymentSetting::current();
        $min = (float) $setting->min_topup_amount;

        return [
            'amount' => ['required', 'numeric', "min:{$min}"],
            'receipt' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,pdf', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        $setting = PaymentSetting::current();
        $min = (float) $setting->min_topup_amount;

        return [
            'amount.min' => sprintf('The minimum top-up amount is ₦%s.', number_format($min, 2)),
        ];
    }
}
