<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

class InvoiceController extends ApiController
{
    public function index(Request $request)
    {
        $invoices = Invoice::query()->with('customer')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->input('customer_id')))
            ->latest()->paginate($request->integer('per_page', 15));
        return $this->successResponse('Invoices retrieved successfully.', $invoices);
    }

    public function store(StoreInvoiceRequest $request, InvoiceService $service)
    {
        return $this->successResponse('Invoice created successfully.', $service->create($request->validated(), $request->user()->id), 201);
    }

    public function show(int $invoice)
    {
        return $this->successResponse('Invoice retrieved successfully.', $this->invoice($invoice)->load('items', 'customer'));
    }

    public function update(UpdateInvoiceRequest $request, int $invoice, InvoiceService $service)
    {
        return $this->successResponse('Invoice updated successfully.', $service->update($this->invoice($invoice), $request->validated()));
    }

    public function destroy(int $invoice)
    {
        $invoice = $this->invoice($invoice);
        if ($invoice->status !== 'draft') return $this->errorResponse('Only draft invoices can be deleted.', 422);
        $invoice->update(['status' => 'cancelled']);
        return $this->successResponse('Invoice cancelled successfully.');
    }

    public function pdf(int $invoice, InvoiceService $service)
    {
        $invoice = $this->invoice($invoice);
        return $service->generatePdf($invoice);
    }

    private function invoice(int $id): Invoice { return Invoice::query()->findOrFail($id); }
}
