<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Invoice\SendInvoiceRequest;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Services\AuditLogger;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

class InvoiceController extends ApiController
{
    public function __construct(private InvoiceService $service, private AuditLogger $audit)
    {
    }

    public function index(Request $request)
    {
        $invoices = Invoice::query()->with('customer:id,name,business_name,phone,email')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date('from_date')))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date('to_date')))
            ->when($request->boolean('open'), fn ($q) => $q->open())
            ->when($request->string('search')->trim()->value(), function ($q, $search) {
                $q->where(fn ($w) => $w
                    ->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('business_name', 'like', "%{$search}%")));
            })
            ->latest('id')
            ->paginate(min($request->integer('per_page', 15), 100));

        return $this->successResponse('Invoices retrieved successfully.', $invoices);
    }

    public function store(StoreInvoiceRequest $request)
    {
        $invoice = $this->service->create($request->validated(), $request->user()->id);
        $this->audit->log('invoice.created', $invoice, null, null, $invoice->only('invoice_number', 'customer_id', 'total', 'status'));

        return $this->successResponse('Invoice created successfully.', $invoice, 201);
    }

    public function show(int $invoice)
    {
        $invoice = $this->invoice($invoice)->load([
            'items', 'customer', 'creator:id,name',
            'payments' => fn ($q) => $q->latest('id'),
            'reminderLogs' => fn ($q) => $q->latest('id')->limit(10),
        ]);

        return $this->successResponse('Invoice retrieved successfully.', $invoice);
    }

    public function update(UpdateInvoiceRequest $request, int $invoice)
    {
        $invoice = $this->invoice($invoice);
        $before = $invoice->only('invoice_number', 'customer_id', 'total', 'status', 'due_date');
        $invoice = $this->service->update($invoice, $request->validated());
        $this->audit->log('invoice.updated', $invoice, null, $before, $invoice->only('invoice_number', 'customer_id', 'total', 'status', 'due_date'));

        return $this->successResponse('Invoice updated successfully.', $invoice);
    }

    /**
     * Invoices are never physically deleted; this cancels them.
     */
    public function destroy(int $invoice)
    {
        $invoice = $this->service->cancel($this->invoice($invoice));
        $this->audit->log('invoice.cancelled', $invoice, null, null, ['status' => 'cancelled']);

        return $this->successResponse('Invoice cancelled successfully.', $invoice);
    }

    public function send(SendInvoiceRequest $request, int $invoice)
    {
        $result = $this->service->send(
            $this->invoice($invoice),
            $request->boolean('via_whatsapp', true),
            $request->input('message'),
            $request->input('template_id'),
        );
        $this->audit->log('invoice.sent', $result['invoice'], null, null, ['via_whatsapp' => $request->boolean('via_whatsapp', true)]);

        return $this->successResponse(
            $result['message'] ? 'Invoice sent and queued for WhatsApp delivery.' : 'Invoice marked as sent.',
            $result,
        );
    }

    public function remind(SendInvoiceRequest $request, int $invoice)
    {
        $message = $this->service->remind($this->invoice($invoice), $request->input('message'), $request->input('template_id'));

        return $this->successResponse('Payment reminder queued for delivery.', $message, 201);
    }

    public function pdf(int $invoice)
    {
        return $this->service->generatePdf($this->invoice($invoice));
    }

    private function invoice(int $id): Invoice
    {
        return Invoice::query()->findOrFail($id);
    }
}
