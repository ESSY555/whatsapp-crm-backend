<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateBusinessRequest;
use App\Http\Requests\Business\UpdateBusinessSettingsRequest;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

class BusinessController extends ApiController
{
    public function show()
    {
        return $this->successResponse('Business retrieved successfully.', $this->business()->load('settings'));
    }

    public function update(UpdateBusinessRequest $request)
    {
        $business = $this->business();
        $business->update($request->validated());

        return $this->successResponse('Business updated successfully.', $business->fresh());
    }

    public function settings()
    {
        $settings = $this->business()->settings()->pluck('value', 'key');

        return $this->successResponse('Business settings retrieved successfully.', $settings);
    }

    public function updateSettings(UpdateBusinessSettingsRequest $request)
    {
        $businessId = TenantManager::businessId();
        DB::transaction(function () use ($request, $businessId) {
            foreach ($request->validated('settings') as $key => $value) {
                BusinessSetting::updateOrCreate(
                    ['business_id' => $businessId, 'key' => $key],
                    ['value' => $value],
                );
            }
        });

        return $this->settings();
    }

    private function business(): Business
    {
        return Business::query()->findOrFail(TenantManager::businessId());
    }
}
