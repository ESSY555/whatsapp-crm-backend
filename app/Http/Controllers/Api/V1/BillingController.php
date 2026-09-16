<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Billing\StorePaymentRequestRequest;
use App\Models\Business;
use App\Models\PaymentRequest;
use App\Models\Plan;
use App\Services\AuditLogger;
use App\Services\BillingService;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;

/**
 * A business's view of its own plan: current entitlement, usage, the price
 * list, where to pay, and its receipt submissions.
 */
class BillingController extends ApiController
{
    public function __construct(private BillingService $billing, private AuditLogger $audit)
    {
    }

    public function show()
    {
        return $this->successResponse('Billing summary retrieved successfully.', $this->billing->summary($this->business()));
    }

    public function plans()
    {
        $plans = Plan::query()->active()->orderBy('sort_order')->orderBy('price')->get()
            ->map(fn (Plan $plan) => $this->billing->presentPlan($plan))->values();

        return $this->successResponse('Plans retrieved successfully.', $plans);
    }

    public function requests(Request $request)
    {
        $list = PaymentRequest::query()->with(['plan', 'submitter:id,name', 'reviewer:id,name'])
            ->latest()->paginate(min((int) $request->query('per_page', 15), 100));

        return $this->successResponse('Payment requests retrieved successfully.', $list);
    }

    public function store(StorePaymentRequestRequest $request)
    {
        $plan = Plan::query()->findOrFail($request->validated('plan_id'));

        $paymentRequest = $this->billing->submitPaymentRequest(
            $this->business(), $request->user(), $plan, $request->file('receipt'), $request->safe()->only('reference', 'note'),
        );

        $this->audit->log('payment_request.submitted', $paymentRequest, null, null, [
            'plan' => $plan->code, 'amount' => (float) $paymentRequest->amount, 'reference' => $paymentRequest->reference,
        ]);

        return $this->successResponse('Receipt uploaded. We will review your payment shortly.', $paymentRequest, 201);
    }

    /**
     * Stream the uploaded receipt. Owners and managers of the business only.
     */
    public function receipt(int $paymentRequest)
    {
        $record = PaymentRequest::query()->findOrFail($paymentRequest);
        $disk = $this->billing->receiptDisk();

        abort_unless($disk->exists($record->receipt_path), 404, 'Receipt file not found.');

        return $disk->response($record->receipt_path, $record->receipt_name ?: basename($record->receipt_path), [
            'Content-Type' => $record->receipt_mime ?: 'application/octet-stream',
        ]);
    }

    private function business(): Business
    {
        return Business::query()->findOrFail(TenantManager::businessId());
    }
}
