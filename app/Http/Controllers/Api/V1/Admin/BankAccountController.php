<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\BankAccount;
use Illuminate\Http\Request;

class BankAccountController extends ApiController
{
    public function index()
    {
        return $this->successResponse('Bank accounts retrieved successfully.', BankAccount::query()->orderBy('sort_order')->get());
    }

    public function store(Request $request)
    {
        $account = BankAccount::create($this->validated($request));

        return $this->successResponse('Bank account added successfully.', $account, 201);
    }

    public function update(Request $request, int $bankAccount)
    {
        $account = BankAccount::query()->findOrFail($bankAccount);
        $account->update($this->validated($request));

        return $this->successResponse('Bank account updated successfully.', $account->fresh());
    }

    public function destroy(int $bankAccount)
    {
        BankAccount::query()->findOrFail($bankAccount)->delete();

        return $this->successResponse('Bank account removed successfully.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'bank_name' => ['required', 'string', 'max:120'],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }
}
