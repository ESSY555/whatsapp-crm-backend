<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * Global quick search used by the command palette.
 */
class SearchController extends ApiController
{
    public function __invoke(Request $request)
    {
        $q = $request->string('q')->trim()->value();
        if (mb_strlen($q) < 1) {
            return $this->successResponse('Search results.', ['customers' => [], 'invoices' => []]);
        }

        $customers = Customer::query()
            ->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('business_name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"))
            ->limit(8)
            ->get(['id', 'name', 'business_name', 'phone']);

        $invoices = Invoice::query()
            ->with('customer:id,name,business_name')
            ->where(fn ($w) => $w
                ->where('invoice_number', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%")->orWhere('business_name', 'like', "%{$q}%")))
            ->latest('id')
            ->limit(8)
            ->get(['id', 'invoice_number', 'customer_id', 'total', 'balance_due', 'status']);

        return $this->successResponse('Search results.', ['customers' => $customers, 'invoices' => $invoices]);
    }
}
