<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\PaymentRequest;
use App\Services\AuditLogger;
use App\Services\BillingService;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;

/**
 * The approval queue. Every decision is written to the business's own audit
 * trail so the owner can see who reviewed their payment and when.
 */
class PaymentRequestController extends ApiController
{
    public function __construct(private BillingService $billing, private AuditLogger $audit)
    {
    }

    public function index(Request $request)
    {
        $query = PaymentRequest::withoutGlobalScopes()
            ->with(['plan:id,code,name,price,currency', 'business:id,name,slug,status', 'submitter:id,name,email', 'reviewer:id,name']);

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

        return $this->successResponse('Payment requests retrieved successfully.', $query->paginate(min((int) $request->query('per_page', 20), 100)));
    }

    public function show(int $paymentRequest)
    {
        $record = $this->find($paymentRequest)->load([
            'plan', 'business', 'submitter:id,name,email', 'reviewer:id,name,email', 'subscription',
        ]);

        $data = $record->toArray();
        $data['amount_mismatch'] = $record->amountMismatch();
        $data['business']['billing'] = $this->billing->summary($record->business);
        $data['history'] = PaymentRequest::withoutGlobalScopes()->where('business_id', $record->business_id)->whereKeyNot($record->id)
            ->with(['plan:id,code,name', 'reviewer:id,name'])->latest()->limit(10)->get();

        return $this->successResponse('Payment request retrieved successfully.', $data);
    }

    public function receipt(int $paymentRequest)
    {
        $record = $this->find($paymentRequest);
        $disk = $this->billing->receiptDisk();

        abort_unless($disk->exists($record->receipt_path), 404, 'Receipt file not found.');

        return $disk->response($record->receipt_path, $record->receipt_name ?: basename($record->receipt_path), [
            'Content-Type' => $record->receipt_mime ?: 'application/octet-stream',
        ]);
    }

    public function approve(Request $request, int $paymentRequest)
    {
        $record = $this->find($paymentRequest);
        $subscription = $this->billing->approve($record, $request->user());

        TenantManager::runAs($record->business_id, fn () => $this->audit->log(
            'payment_request.approved', $record, null, null,
            ['plan' => $subscription->plan->code, 'starts_at' => $subscription->starts_at, 'ends_at' => $subscription->ends_at],
        ));

        return $this->successResponse('Payment approved and subscription activated.', [
            'payment_request' => $record->fresh(['plan', 'reviewer:id,name']),
            'subscription' => $subscription,
        ]);
    }

    public function decline(Request $request, int $paymentRequest)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        $record = $this->find($paymentRequest);
        $this->billing->decline($record, $request->user(), $validated['reason']);

        TenantManager::runAs($record->business_id, fn () => $this->audit->log(
            'payment_request.declined', $record, null, null, ['reason' => $validated['reason']],
        ));

        return $this->successResponse('Payment declined.', $record->fresh(['plan', 'reviewer:id,name']));
    }

    private function find(int $id): PaymentRequest
    {
        return PaymentRequest::withoutGlobalScopes()->findOrFail($id);
    }
}
