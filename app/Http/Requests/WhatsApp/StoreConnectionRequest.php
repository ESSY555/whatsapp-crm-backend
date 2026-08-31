<?php

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class StoreConnectionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'phone_number_id' => ['required', 'string', 'max:255'],
            'business_account_id' => ['nullable', 'string', 'max:255'],
            'access_token' => ['required', 'string', 'min:10'],
        ];
    }
}
