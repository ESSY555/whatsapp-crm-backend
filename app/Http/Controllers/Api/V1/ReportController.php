<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends ApiController
{
    public function __construct(private ReportService $reports)
    {
    }

    public function dashboard()
    {
        return $this->successResponse('Dashboard retrieved successfully.', $this->reports->dashboard());
    }

    public function sales(Request $request)
    {
        [$from, $to] = $this->range($request);

        return $this->successResponse('Sales report retrieved successfully.', $this->reports->sales($from, $to));
    }

    public function customers(Request $request)
    {
        [$from, $to] = $this->range($request);

        return $this->successResponse('Customer report retrieved successfully.', $this->reports->customers($from, $to));
    }

    public function debt(Request $request)
    {
        $request->validate(['customer_id' => ['nullable', 'integer']]);

        return $this->successResponse('Debt report retrieved successfully.', $this->reports->ageing($request->integer('customer_id') ?: null));
    }

    public function messaging(Request $request)
    {
        [$from, $to] = $this->range($request);

        return $this->successResponse('Messaging report retrieved successfully.', $this->reports->messaging($from, $to));
    }

    private function range(Request $request): array
    {
        $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        return [
            $request->filled('from_date') ? $request->date('from_date')->startOfDay() : null,
            $request->filled('to_date') ? $request->date('to_date')->endOfDay() : null,
        ];
    }
}
