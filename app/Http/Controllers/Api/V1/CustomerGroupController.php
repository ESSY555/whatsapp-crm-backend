<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CustomerGroup\StoreCustomerGroupRequest;
use App\Http\Requests\CustomerGroup\UpdateCustomerGroupRequest;
use App\Models\CustomerGroup;
use Illuminate\Http\Request;

class CustomerGroupController extends ApiController
{
    public function index(Request $request)
    {
        $groups = CustomerGroup::query()
            ->withCount('customers')
            ->when($request->string('search')->trim()->value(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse('Customer groups retrieved successfully.', $groups);
    }

    public function store(StoreCustomerGroupRequest $request)
    {
        return $this->successResponse('Customer group created successfully.', CustomerGroup::create($request->validated()), 201);
    }

    public function show(int $customerGroup)
    {
        return $this->successResponse('Customer group retrieved successfully.', $this->group($customerGroup)->load('customers'));
    }

    public function update(UpdateCustomerGroupRequest $request, int $customerGroup)
    {
        $customerGroup = $this->group($customerGroup);
        $customerGroup->update($request->validated());

        return $this->successResponse('Customer group updated successfully.', $customerGroup->fresh());
    }

    public function destroy(int $customerGroup)
    {
        $customerGroup = $this->group($customerGroup);
        $customerGroup->customers()->detach();
        $customerGroup->delete();

        return $this->successResponse('Customer group deleted successfully.');
    }

    private function group(int $id): CustomerGroup
    {
        return CustomerGroup::query()->findOrFail($id);
    }
}
