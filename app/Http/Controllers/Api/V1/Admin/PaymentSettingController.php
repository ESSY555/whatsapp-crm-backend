<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\UpdatePaymentSettingsRequest;
use App\Models\BankAccount;
use App\Models\PaymentSetting;

class PaymentSettingController extends ApiController
{
    /**
     * Get platform payment and wallet configuration settings.
     */
    public function show()
    {
        $setting = PaymentSetting::current();
        $bankAccounts = BankAccount::query()->where('is_active', true)->orderBy('sort_order')->get();

        return $this->successResponse('Payment settings retrieved successfully.', [
            'settings' => $setting->load(['bankAccount', 'updater:id,name']),
            'computed_pricing' => [
                'utility_price_per_message' => $setting->calculatePricePerMessage('utility'),
                'messages_per_min_topup' => $setting->calculateMessagesFor((float) $setting->min_topup_amount, 'utility'),
            ],
            'bank_accounts' => $bankAccounts,
        ]);
    }

    /**
     * Update platform payment and wallet configuration settings.
     */
    public function update(UpdatePaymentSettingsRequest $request)
    {
        $setting = PaymentSetting::current();

        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;

        $setting->update($data);

        return $this->successResponse('Payment settings updated successfully.', [
            'settings' => $setting->fresh(['bankAccount', 'updater:id,name']),
            'computed_pricing' => [
                'utility_price_per_message' => $setting->calculatePricePerMessage('utility'),
                'messages_per_min_topup' => $setting->calculateMessagesFor((float) $setting->min_topup_amount, 'utility'),
            ],
        ]);
    }
}
