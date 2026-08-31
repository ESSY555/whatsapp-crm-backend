<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function record(array $data, int $userId): Payment
    {
        return DB::transaction(function () use ($data, $userId) {
            // Lock the invoice so concurrent requests cannot both spend its balance.
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
            ]);

            $paid = round((float) $invoice->amount_paid + $amount, 2);
            $newBalance = round(max(0, (float) $invoice->total - $paid), 2);
            $status = $newBalance <= 0 ? 'paid' : 'partially_paid';
            $invoice->forceFill([
                'amount_paid' => $paid,
                'balance_due' => $newBalance,
                'status' => $status,
            ])->save();

            return $payment->load('invoice', 'customer');
        });
    }
}
