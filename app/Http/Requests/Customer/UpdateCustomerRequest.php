<?php

namespace App\Http\Requests\Customer;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'][0] = 'sometimes';
        $rules['group_ids'][0] = 'sometimes';

        return $rules;
    }
}
