<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Payment\RecordPaymentRequest;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    public function index(Request $request)
    {
        $payments = Payment::query()->with(['customer', 'invoice'])
            ->when($request->filled('invoice_id'), fn ($q) => $q->where('invoice_id', $request->input('invoice_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->input('customer_id')))
            ->latest('payment_date')->paginate($request->integer('per_page', 15));
        return $this->successResponse('Payments retrieved successfully.', $payments);
    }

    public function store(RecordPaymentRequest $request, PaymentService $service)
    {
        return $this->successResponse('Payment recorded successfully.', $service->record($request->validated(), $request->user()->id), 201);
    }

    public function show(int $payment)
    {
        return $this->successResponse('Payment retrieved successfully.', Payment::query()->with(['invoice', 'customer'])->findOrFail($payment));
    }
}
