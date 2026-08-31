<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateInvoicePdf implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private Invoice $invoice
    ) {}

    /**
     * Execute the job.
     */
    public function handle(InvoicePdfService $pdfService): void
    {
        try {
            $pdfService->generateAndStore($this->invoice);

            // Optional: Update invoice with PDF generation timestamp
            // $this->invoice->update(['pdf_generated_at' => now()]);
        } catch (\Exception $e) {
            \Log::error("Failed to generate PDF for invoice {$this->invoice->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['invoice', "invoice:{$this->invoice->id}"];
    }
}
