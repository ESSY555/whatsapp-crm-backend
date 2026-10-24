<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\TopupRequest;
use App\Services\WalletService;
use Illuminate\Http\Request;

class TopupRequestController extends ApiController
{
    public function __construct(private WalletService $walletService)
    {
    }

    /**
     * Admin review queue for wallet top-up requests across all businesses.
     */
    public function index(Request $request)
    {
        $query = TopupRequest::withoutGlobalScopes()
            ->with(['business:id,name,slug,status', 'submitter:id,name,email', 'reviewer:id,name']);

        $status = $request->query('status', 'pending');
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($businessId = $request->query('business_id')) {
            $query->where('business_id', (int) $businessId);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('business', fn ($b) => $b->where('name', 'like', "%{$search}%"));
            });
        }

        $query->orderBy('created_at', $status === 'pending' ? 'asc' : 'desc');

        $perPage = min((int) $request->query('per_page', 20), 100);

        return $this->successResponse('Top-up requests retrieved successfully.', $query->paginate($perPage));
    }

    /**
     * Detailed view of a top-up request with business wallet summary and history.
     */
    public function show(int $topupRequestId)
    {
        $record = TopupRequest::withoutGlobalScopes()
            ->with(['business', 'submitter:id,name,email', 'reviewer:id,name'])
            ->findOrFail($topupRequestId);

        $wallet = $this->walletService->getOrCreateWallet($record->business);

        $data = $record->toArray();
        $data['business']['wallet'] = [
            'balance' => (float) $wallet->balance,
            'currency' => $wallet->currency,
            'low_balance_alerted' => (bool) $wallet->low_balance_alerted,
        ];
        $data['history'] = TopupRequest::withoutGlobalScopes()
            ->where('business_id', $record->business_id)
            ->whereKeyNot($record->id)
            ->with(['reviewer:id,name'])
            ->latest()
            ->limit(10)
            ->get();

        return $this->successResponse('Top-up request retrieved successfully.', $data);
    }

    /**
     * Stream or download the uploaded receipt file.
     */
    public function receipt(int $topupRequestId)
    {
        $record = TopupRequest::withoutGlobalScopes()->findOrFail($topupRequestId);
        $disk = $this->walletService->receiptDisk();

        abort_unless($disk->exists($record->receipt_path), 404, 'Receipt file not found.');

        return $disk->response($record->receipt_path, $record->receipt_name ?: basename($record->receipt_path), [
            'Content-Type' => $record->receipt_mime ?: 'application/octet-stream',
        ]);
    }

    /**
     * Approve top-up: credits tenant wallet with the submitted amount.
     * The admin cannot edit the amount.
     */
    public function approve(Request $request, int $topupRequestId)
    {
        $record = TopupRequest::withoutGlobalScopes()->findOrFail($topupRequestId);
        $updated = $this->walletService->approveTopup($record, $request->user());

        return $this->successResponse('Top-up request approved and wallet credited successfully.', [
            'topup_request' => $updated,
        ]);
    }

    /**
     * Reject top-up with mandatory reason.
     */
    public function reject(Request $request, int $topupRequestId)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $record = TopupRequest::withoutGlobalScopes()->findOrFail($topupRequestId);
        $updated = $this->walletService->rejectTopup($record, $request->user(), $validated['reason']);

        return $this->successResponse('Top-up request rejected.', [
            'topup_request' => $updated,
        ]);
    }
}
