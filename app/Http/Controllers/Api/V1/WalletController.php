<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Wallet\SubmitTopupRequest;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\PaymentSetting;
use App\Models\TopupRequest;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;

class WalletController extends ApiController
{
    public function __construct(private WalletService $walletService)
    {
    }

    /**
     * Get wallet status, balance, pricing and bank details for the tenant.
     */
    public function show()
    {
        $business = Business::findOrFail(TenantManager::businessId());
        $wallet = $this->walletService->getOrCreateWallet($business);
        $setting = PaymentSetting::current();

        $pricePerMessage = $setting->calculatePricePerMessage('utility');
        $minTopup = (float) $setting->min_topup_amount;
        $threshold = (float) $setting->low_balance_threshold;

        $bankAccounts = BankAccount::query()->where('is_active', true)->orderBy('sort_order')->get();
        $pendingTopup = TopupRequest::query()->where('business_id', $business->id)->pending()->latest()->first();
        $lastRejected = TopupRequest::query()->where('business_id', $business->id)->rejected()->latest()->first();

        return $this->successResponse('Wallet details retrieved successfully.', [
            'wallet' => [
                'id' => $wallet->id,
                'business_id' => $wallet->business_id,
                'balance' => (float) $wallet->balance,
                'currency' => $wallet->currency,
                'low_balance_alerted' => (bool) $wallet->low_balance_alerted,
                'is_low_balance' => (float) $wallet->balance < $threshold,
            ],
            'pricing' => [
                'price_per_message' => $pricePerMessage,
                'min_topup_amount' => $minTopup,
                'low_balance_threshold' => $threshold,
                'messages_per_min_topup' => $setting->calculateMessagesFor($minTopup, 'utility'),
                'meta_utility_rate_usd' => (float) $setting->meta_utility_rate_usd,
                'usd_ngn_rate' => (float) $setting->usd_ngn_rate,
                'markup_percent' => (float) $setting->markup_percent,
                'vat_percent' => (float) $setting->vat_percent,
            ],
            'bank_accounts' => $bankAccounts,
            'pending_topup' => $pendingTopup,
            'last_rejected_topup' => $lastRejected,
        ]);
    }

    /**
     * Paginated ledger of wallet transactions for the tenant.
     */
    public function transactions(Request $request)
    {
        $businessId = TenantManager::businessId();
        $query = WalletTransaction::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->orderBy('id', 'desc');

        if ($type = $request->query('type')) {
            if (in_array($type, ['credit', 'debit'], true)) {
                $query->where('type', $type);
            }
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return $this->successResponse('Wallet transactions retrieved successfully.', $query->paginate($perPage));
    }

    /**
     * Paginated list of top-up requests submitted by the tenant.
     */
    public function topups(Request $request)
    {
        $businessId = TenantManager::businessId();
        $query = TopupRequest::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->with(['submitter:id,name,email', 'reviewer:id,name'])
            ->orderBy('created_at', 'desc');

        if ($status = $request->query('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return $this->successResponse('Top-up requests retrieved successfully.', $query->paginate($perPage));
    }

    /**
     * Submit a new top-up request with payment receipt.
     */
    public function submitTopup(SubmitTopupRequest $request)
    {
        $business = Business::findOrFail(TenantManager::businessId());

        $record = $this->walletService->submitTopup(
            $business,
            $request->user(),
            $request->file('receipt'),
            (float) $request->input('amount'),
            $request->input('reference'),
            $request->input('note')
        );

        return $this->successResponse('Top-up request submitted successfully. Our team will review your receipt.', $record, 201);
    }

    /**
     * Download or stream the top-up receipt for the tenant.
     */
    public function receipt(int $topupRequestId)
    {
        $businessId = TenantManager::businessId();
        $record = TopupRequest::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->findOrFail($topupRequestId);

        $disk = $this->walletService->receiptDisk();
        abort_unless($disk->exists($record->receipt_path), 404, 'Receipt file not found.');

        return $disk->response($record->receipt_path, $record->receipt_name ?: basename($record->receipt_path), [
            'Content-Type' => $record->receipt_mime ?: 'application/octet-stream',
        ]);
    }

    /**
     * Live estimate calculator for amount to messages.
     */
    public function estimate(Request $request)
    {
        $amount = (float) $request->query('amount', 0);
        $setting = PaymentSetting::current();
        $price = $setting->calculatePricePerMessage('utility');
        $messages = $setting->calculateMessagesFor($amount, 'utility');

        return $this->successResponse('Estimate calculated successfully.', [
            'amount' => $amount,
            'price_per_message' => $price,
            'messages_estimate' => $messages,
        ]);
    }
}
