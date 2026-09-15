<?php

namespace App\Http\Requests\Customer;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportCustomersRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
            'duplicates' => ['nullable', Rule::in(['skip', 'update'])],
            'create_groups' => ['nullable', 'boolean'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', Rule::exists('customer_groups', 'id')->where('business_id', TenantManager::businessId())],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV file to import.',
            'file.mimes' => 'The import file must be a CSV.',
            'file.mimetypes' => 'The import file must be a CSV.',
            'file.max' => 'The import file may not be larger than 2 MB.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Multipart forms send booleans and arrays as strings.
        if ($this->has('create_groups')) {
            $this->merge(['create_groups' => filter_var($this->input('create_groups'), FILTER_VALIDATE_BOOLEAN)]);
        }
        if (is_string($this->input('group_ids'))) {
            $this->merge(['group_ids' => array_values(array_filter(array_map('intval', explode(',', $this->input('group_ids')))))]);
        }
    }
}
