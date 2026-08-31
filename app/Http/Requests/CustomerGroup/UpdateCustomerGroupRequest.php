<?php

namespace App\Http\Requests\CustomerGroup;

class UpdateCustomerGroupRequest extends StoreCustomerGroupRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
