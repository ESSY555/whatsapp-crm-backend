<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Payment\RecordPaymentRequest;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    public function __construct(private PaymentService $service)
    {
    }

    public function index(Request $request)
    {
        $payments = Payment::query()->with(['customer:id,name,business_name', 'invoice:id,invoice_number,total,balance_due,status', 'recorder:id,name'])
            ->when($request->filled('invoice_id'), fn ($q) => $q->where('invoice_id', $request->integer('invoice_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->input('payment_method')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('payment_date', '>=', $request->date('from_date')))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('payment_date', '<=', $request->date('to_date')))
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 15), 100));

        return $this->successResponse('Payments retrieved successfully.', $payments);
    }

    public function store(RecordPaymentRequest $request)
    {
        $key = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');
        $payment = $this->service->record($request->validated(), $request->user()->id, $key ? substr($key, 0, 100) : null);

        return $this->successResponse('Payment recorded successfully.', $payment, $payment->wasRecentlyCreated ? 201 : 200);
    }

    public function show(int $payment)
    {
        return $this->successResponse(
            'Payment retrieved successfully.',
            Payment::query()->with(['invoice', 'customer', 'recorder:id,name', 'reversalOf'])->findOrFail($payment),
        );
    }

    public function reverse(Request $request, int $payment)
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $payment = Payment::query()->findOrFail($payment);

        $reversal = $this->service->reverse($payment, $request->user()->id, $validated['reason'] ?? null);

        return $this->successResponse('Payment reversed successfully.', $reversal, 201);
    }
}
