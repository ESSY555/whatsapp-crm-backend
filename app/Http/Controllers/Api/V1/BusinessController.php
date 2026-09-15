<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateBusinessRequest;
use App\Http\Requests\Business\UpdateBusinessSettingsRequest;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\WhatsAppConnection;
use App\Services\AuditLogger;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BusinessController extends ApiController
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function show(Request $request)
    {
        $business = $this->business()->load('settings');
        $data = $business->toArray();
        $data['settings'] = $business->settings->pluck('value', 'key');
        $data['logo_url'] = $this->logoUrl($business);
        $data['role'] = $request->user()->roleIn($business->id);
        $data['whatsapp_connected'] = WhatsAppConnection::query()->active()->exists();

        return $this->successResponse('Business retrieved successfully.', $data);
    }

    public function update(UpdateBusinessRequest $request)
    {
        $business = $this->business();
        $business->update($request->validated());
        $this->audit->logChanges('business.updated', $business);

        $data = $business->fresh()->toArray();
        $data['logo_url'] = $this->logoUrl($business);

        return $this->successResponse('Business updated successfully.', $data);
    }

    /**
     * Upload a logo (stored on the public disk, never in the database).
     */
    public function uploadLogo(Request $request)
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048']]);

        $business = $this->business();
        if ($business->logo && Storage::disk('public')->exists($business->logo)) {
            Storage::disk('public')->delete($business->logo);
        }

        $path = $request->file('logo')->store("logos/business_{$business->id}", 'public');
        $business->update(['logo' => $path]);

        return $this->successResponse('Logo uploaded successfully.', ['logo' => $path, 'logo_url' => $this->logoUrl($business)]);
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
        $this->audit->log('business.settings_updated', 'Business', $businessId, null, $request->validated('settings'));

        return $this->settings();
    }

    private function business(): Business
    {
        return Business::query()->findOrFail(TenantManager::businessId());
    }

    private function logoUrl(Business $business): ?string
    {
        if (!$business->logo) {
            return null;
        }
        if (str_starts_with($business->logo, 'http')) {
            return $business->logo;
        }
        return Storage::disk('public')->url($business->logo);
    }
}
