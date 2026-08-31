<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\Request;

class CustomerController extends ApiController
{
    public function index(Request $request)
    {
        $customers = Customer::query()
            ->with('groups')
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse('Customers retrieved successfully.', $customers);
    }

    public function store(StoreCustomerRequest $request, CustomerService $customers)
    {
        return $this->successResponse(
            'Customer created successfully.',
            $customers->create($request->validated()),
            201,
        );
    }

    public function show(int $customer)
    {
        return $this->successResponse('Customer retrieved successfully.', $this->customer($customer)->load('groups'));
    }

    public function update(UpdateCustomerRequest $request, int $customer, CustomerService $customers)
    {
        return $this->successResponse(
            'Customer updated successfully.',
            $customers->update($this->customer($customer), $request->validated()),
        );
    }

    public function destroy(int $customer)
    {
        $this->customer($customer)->delete();

        return $this->successResponse('Customer deleted successfully.');
    }

    private function customer(int $id): Customer
    {
        return Customer::query()->findOrFail($id);
    }
}
