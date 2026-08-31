<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'max:50'],
            'settings.*' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
