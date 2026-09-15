<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private InvoiceService $invoices,
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {}

    /**
     * Record a payment against an invoice. The invoice row is locked so two
     * concurrent requests cannot both consume the same balance, and an
     * idempotency key makes a repeated request return the original payment.
     */
    public function record(array $data, int $userId, ?string $idempotencyKey = null): Payment
    {
        if ($idempotencyKey) {
            $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load('invoice', 'customer');
            }
        }

        return DB::transaction(function () use ($data, $userId, $idempotencyKey) {
            $invoice = Invoice::query()->lockForUpdate()->find($data['invoice_id']);
            if (!$invoice) {
                throw ValidationException::withMessages(['invoice_id' => ['The selected invoice is invalid.']]);
            }
            if ($invoice->status === 'cancelled') {
                throw ValidationException::withMessages(['invoice_id' => ['Cancelled invoices cannot receive payments.']]);
            }

            $amount = round((float) $data['amount'], 2);
            $balance = round((float) $invoice->balance_due, 2);
            if ($amount > $balance) {
                throw ValidationException::withMessages(['amount' => ['Payment cannot exceed the invoice balance due.']]);
            }

            $payment = Payment::create([
                'business_id' => TenantManager::businessId(),
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $userId,
                'status' => 'completed',
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->invoices->recalculate($invoice->load('items'));

            $payment->load('invoice', 'customer');
            $this->notifications->paymentReceived($payment);
            $this->audit->log('payment.recorded', $payment, null, null, $payment->only('invoice_id', 'amount', 'payment_method', 'reference'));

            return $payment;
        });
    }

    /**
     * Reverse a payment by recording an offsetting entry. The original row is
     * never edited, so the ledger stays auditable.
     */
    public function reverse(Payment $payment, int $userId, ?string $reason = null): Payment
    {
        if ($payment->status !== 'completed') {
            throw ValidationException::withMessages(['payment' => ['Only completed payments can be reversed.']]);
        }

        return DB::transaction(function () use ($payment, $userId, $reason) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);

            $reversal = Payment::create([
                'business_id' => TenantManager::businessId(),
                'customer_id' => $payment->customer_id,
                'invoice_id' => $payment->invoice_id,
                'amount' => -1 * (float) $payment->amount,
                'payment_method' => $payment->payment_method,
                'reference' => $payment->reference ? 'REV-' . $payment->reference : null,
                'payment_date' => now()->toDateString(),
                'notes' => $reason ?: 'Reversal of payment #' . $payment->id,
                'recorded_by' => $userId,
                'status' => 'reversal',
                'reversal_of_id' => $payment->id,
            ]);

            $payment->update(['status' => 'reversed', 'reversed_at' => now()]);

            $this->invoices->recalculate($invoice->load('items'));
            $this->audit->log('payment.reversed', $payment, null, ['status' => 'completed'], ['status' => 'reversed', 'reversal_id' => $reversal->id, 'reason' => $reason]);

            return $reversal->load('invoice', 'customer');
        });
    }
}
