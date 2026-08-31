<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Invoice;
use Illuminate\Http\Request;

class DebtReportController extends ApiController
{
    public function index(Request $request)
    {
        $invoices = Invoice::query()->with('customer')->where('balance_due', '>', 0)
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->input('customer_id')))
            ->get();
        $buckets = ['current' => 0, '1_30_days' => 0, '31_60_days' => 0, '61_90_days' => 0, '90_plus_days' => 0];
        $customers = [];
        foreach ($invoices as $invoice) {
            $days = $invoice->due_date && $invoice->due_date->isPast() ? $invoice->due_date->diffInDays(today()) : 0;
            $bucket = $days === 0 ? 'current' : ($days <= 30 ? '1_30_days' : ($days <= 60 ? '31_60_days' : ($days <= 90 ? '61_90_days' : '90_plus_days')));
            $amount = (float) $invoice->balance_due;
            $buckets[$bucket] += $amount;
            $customers[$invoice->customer_id] = ($customers[$invoice->customer_id] ?? 0) + $amount;
        }
        return $this->successResponse('Debt report retrieved successfully.', [
            'total_outstanding' => round(array_sum($buckets), 2),
            'overdue_amount' => round(array_sum(array_slice($buckets, 1)), 2),
            'ageing' => array_map(fn ($amount) => round($amount, 2), $buckets),
            'customer_balances' => $customers,
        ]);
    }
}
