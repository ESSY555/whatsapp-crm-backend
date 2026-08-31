<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function create(array $data, int $userId): Invoice
    {
        return DB::transaction(function () use ($data, $userId) {
            $items = $data['items'];
            unset($data['items']);
            $data['invoice_number'] ??= $this->nextNumber();
            $data['issue_date'] ??= now()->toDateString();
            $data['discount'] = round((float) ($data['discount'] ?? 0), 2);
            $data['created_by'] = $userId;
            $data['status'] = $data['status'] ?? 'draft';

            $this->assertCustomer($data['customer_id']);
            $invoice = Invoice::create($data);
            $this->replaceItems($invoice, $items);

            return $this->recalculate($invoice->load('items', 'customer'));
        });
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        if (!in_array($invoice->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['status' => ['Only draft or sent invoices can be edited.']]);
        }

        return DB::transaction(function () use ($invoice, $data) {
            $items = $data['items'] ?? null;
            unset($data['items']);
            if (isset($data['customer_id'])) $this->assertCustomer($data['customer_id']);
            $invoice->update($data);
            if ($items !== null) {
                $invoice->items()->delete();
                $this->replaceItems($invoice, $items);
            }
            return $this->recalculate($invoice->refresh()->load('items', 'customer'));
        });
    }

    private function replaceItems(Invoice $invoice, array $items): void
    {
        foreach ($items as $item) {
            $product = isset($item['product_id']) ? Product::query()->find($item['product_id']) : null;
            if (!$product && empty($item['unit_price'])) {
                throw ValidationException::withMessages(['items' => ['Custom invoice lines require a unit_price.']]);
            }
            $quantity = (float) $item['quantity'];
            $unitPrice = round((float) ($product?->unit_price ?? $item['unit_price']), 2);
            $taxRate = round((float) ($product?->tax_rate ?? ($item['tax_rate'] ?? 0)), 2);
            $subtotal = round($quantity * $unitPrice, 2);
            $tax = round($subtotal * $taxRate / 100, 2);

            $invoice->items()->create([
                'business_id' => TenantManager::businessId(),
                'product_id' => $product?->id,
                'description' => $item['description'] ?? $product?->name ?? 'Item',
                'quantity' => $quantity, 'unit_price' => $unitPrice,
                'tax_rate' => $taxRate, 'tax_amount' => $tax,
                'subtotal' => $subtotal, 'total' => round($subtotal + $tax, 2),
            ]);
        }
    }

    private function recalculate(Invoice $invoice): Invoice
    {
        $subtotal = round((float) $invoice->items->sum('subtotal'), 2);
        $tax = round((float) $invoice->items->sum('tax_amount'), 2);
        $discount = min(round((float) $invoice->discount, 2), round($subtotal + $tax, 2));
        $total = round(max(0, $subtotal + $tax - $discount), 2);
        $paid = round((float) ($invoice->amount_paid ?? 0), 2);
        $invoice->forceFill([
            'subtotal' => $subtotal, 'tax' => $tax, 'discount' => $discount,
            'total' => $total, 'balance_due' => max(0, $total - $paid),
        ])->save();
        return $invoice->fresh(['items', 'customer']);
    }

    private function assertCustomer(int $id): void
    {
        if (!Customer::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['customer_id' => ['The selected customer is invalid.']]);
        }
    }

    private function nextNumber(): string
    {
        $prefix = 'INV-' . now()->format('Y') . '-';
        $last = Invoice::query()->where('invoice_number', 'like', $prefix . '%')->lockForUpdate()->orderByDesc('id')->value('invoice_number');
        $sequence = $last ? ((int) substr($last, -6)) + 1 : 1;
        return $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
