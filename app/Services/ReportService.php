<?php

namespace App\Services;

use App\Models\Broadcast;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregations for the dashboard and reports. Every query runs through the
 * tenant-scoped models, so results are always limited to the current business.
 */
class ReportService
{
    public function dashboard(): array
    {
        $open = Invoice::query()->open();
        $invoiced = Invoice::query()->whereNotIn('status', ['draft', 'cancelled']);

        $monthStart = now()->startOfMonth();

        return [
            'metrics' => [
                'total_customers' => Customer::query()->count(),
                'total_invoices' => (clone $invoiced)->count(),
                'total_sales' => round((float) (clone $invoiced)->sum('total'), 2),
                'total_collections' => round((float) Payment::query()->sum('amount'), 2),
                'total_outstanding' => round((float) (clone $open)->sum('balance_due'), 2),
                'overdue_amount' => round((float) Invoice::query()->where('status', 'overdue')->sum('balance_due'), 2),
                'open_invoices' => (clone $open)->count(),
                'overdue_invoices' => Invoice::query()->where('status', 'overdue')->count(),
                'sales_this_month' => round((float) (clone $invoiced)->where('issue_date', '>=', $monthStart)->sum('total'), 2),
                'collections_this_month' => round((float) Payment::query()->where('payment_date', '>=', $monthStart)->sum('amount'), 2),
                'payments_this_month' => Payment::query()->completed()->where('payment_date', '>=', $monthStart)->count(),
                'new_customers_this_month' => Customer::query()->where('created_at', '>=', $monthStart)->count(),
                'messages_sent_this_month' => Message::query()->outbound()->where('created_at', '>=', $monthStart)->count(),
            ],
            'revenue_trend' => $this->monthlyTrend(6),
            'ageing' => $this->ageing()['buckets'],
            'recent_invoices' => Invoice::query()->with('customer:id,name,business_name')->latest('id')->limit(5)->get(),
            'recent_payments' => Payment::query()->completed()->with(['customer:id,name,business_name', 'invoice:id,invoice_number'])->latest('id')->limit(5)->get(),
            'recent_messages' => Message::query()->with('customer:id,name,business_name')->latest('id')->limit(5)->get(),
            'recent_activity' => $this->recentActivity(10),
        ];
    }

    /**
     * Invoiced vs collected per month for the last N months.
     */
    public function monthlyTrend(int $months = 6, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $to = $to ? Carbon::parse($to)->endOfMonth() : now()->endOfMonth();
        $from = $from ? Carbon::parse($from)->startOfMonth() : $to->copy()->subMonths($months - 1)->startOfMonth();

        $invoiced = Invoice::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->get(['issue_date', 'total'])
            ->groupBy(fn ($i) => $i->issue_date->format('Y-m'))
            ->map(fn ($g) => round((float) $g->sum('total'), 2));

        $collected = Payment::query()
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->get(['payment_date', 'amount'])
            ->groupBy(fn ($p) => $p->payment_date->format('Y-m'))
            ->map(fn ($g) => round((float) $g->sum('amount'), 2));

        $trend = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $trend[] = [
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'invoiced' => $invoiced[$key] ?? 0.0,
                'collected' => $collected[$key] ?? 0.0,
            ];
        }

        return $trend;
    }

    public function sales(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $range = fn ($q) => $q
            ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to));

        $invoices = $range(Invoice::query()->whereNotIn('status', ['draft', 'cancelled']));

        $byStatus = (clone $invoices)->selectRaw('status, count(*) as count, sum(total) as total, sum(balance_due) as balance_due')
            ->groupBy('status')->get()
            ->mapWithKeys(fn ($r) => [$r->status => ['count' => (int) $r->count, 'total' => round((float) $r->total, 2), 'balance_due' => round((float) $r->balance_due, 2)]]);

        $payments = Payment::query()
            ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to));

        $topProducts = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.business_id', \App\Tenancy\TenantManager::businessId())
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->when($from, fn ($q) => $q->whereDate('invoices.issue_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('invoices.issue_date', '<=', $to))
            ->selectRaw('invoice_items.description as name, sum(invoice_items.quantity) as quantity, sum(invoice_items.total) as revenue')
            ->groupBy('invoice_items.description')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => (float) $r->quantity, 'revenue' => round((float) $r->revenue, 2)]);

        return [
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'total_sales' => round((float) (clone $invoices)->sum('total'), 2),
            'invoice_count' => (clone $invoices)->count(),
            'paid_invoices' => (clone $invoices)->where('status', 'paid')->count(),
            'outstanding_invoices' => (clone $invoices)->whereIn('status', Invoice::OPEN_STATUSES)->count(),
            'total_collected' => round((float) $payments->sum('amount'), 2),
            'total_outstanding' => round((float) (clone $invoices)->whereIn('status', Invoice::OPEN_STATUSES)->sum('balance_due'), 2),
            'average_invoice' => round((float) (clone $invoices)->avg('total'), 2),
            'by_status' => $byStatus,
            'monthly_trend' => $this->monthlyTrend(6, $from, $to),
            'top_products' => $topProducts,
        ];
    }

    public function customers(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $groups = CustomerGroup::query()->withCount('customers')->orderByDesc('customers_count')->get()
            ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'customers' => $g->customers_count]);

        $top = Customer::query()
            ->withSum(['invoices as revenue' => fn ($q) => $q->whereNotIn('status', ['draft', 'cancelled'])
                ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))], 'total')
            ->withSum(['invoices as outstanding' => fn ($q) => $q->whereIn('status', Invoice::OPEN_STATUSES)], 'balance_due')
            ->withCount('invoices')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->filter(fn ($c) => (float) $c->revenue > 0)
            ->values()
            ->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'business_name' => $c->business_name,
                'revenue' => round((float) $c->revenue, 2), 'outstanding' => round((float) $c->outstanding, 2),
                'invoices_count' => $c->invoices_count,
            ]);

        return [
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'total_customers' => Customer::query()->count(),
            'active_customers' => Customer::query()->where('status', 'active')->count(),
            'new_customers' => Customer::query()
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
                ->count(),
            'customers_with_debt' => Customer::query()->whereHas('invoices', fn ($q) => $q->open())->count(),
            'groups' => $groups,
            'by_category' => Customer::query()->selectRaw("coalesce(category, 'uncategorised') as category, count(*) as count")
                ->groupBy('category')->get()->map(fn ($r) => ['category' => $r->category, 'count' => (int) $r->count]),
            'top_customers' => $top,
        ];
    }

    public function ageing(?int $customerId = null): array
    {
        $invoices = Invoice::query()->open()->with('customer:id,name,business_name,phone')
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->get();

        $buckets = ['current' => 0.0, '1_30_days' => 0.0, '31_60_days' => 0.0, '61_90_days' => 0.0, '90_plus_days' => 0.0];
        $customers = [];

        foreach ($invoices as $invoice) {
            $days = $invoice->daysOverdue();
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30_days',
                $days <= 60 => '31_60_days',
                $days <= 90 => '61_90_days',
                default => '90_plus_days',
            };
            $amount = (float) $invoice->balance_due;
            $buckets[$bucket] += $amount;

            $c = &$customers[$invoice->customer_id];
            $c ??= [
                'customer_id' => $invoice->customer_id,
                'name' => $invoice->customer?->name,
                'business_name' => $invoice->customer?->business_name,
                'phone' => $invoice->customer?->phone,
                'balance' => 0.0, 'overdue' => 0.0, 'invoices' => 0, 'oldest_days_overdue' => 0,
            ];
            $c['balance'] += $amount;
            $c['overdue'] += $days > 0 ? $amount : 0;
            $c['invoices']++;
            $c['oldest_days_overdue'] = max($c['oldest_days_overdue'], $days);
            unset($c);
        }

        $balances = collect($customers)->map(function ($c) {
            $c['balance'] = round($c['balance'], 2);
            $c['overdue'] = round($c['overdue'], 2);
            return $c;
        })->sortByDesc('balance')->values()->all();

        return [
            'total_outstanding' => round(array_sum($buckets), 2),
            'overdue_amount' => round(array_sum(array_slice($buckets, 1)), 2),
            'current_amount' => round($buckets['current'], 2),
            'open_invoices' => $invoices->count(),
            'overdue_invoices' => $invoices->filter(fn ($i) => $i->daysOverdue() > 0)->count(),
            'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
            'ageing' => array_map(fn ($v) => round($v, 2), $buckets),
            'customer_balances' => $balances,
            'invoices' => $invoices->map(fn ($i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'customer_id' => $i->customer_id,
                'customer' => $i->customer?->only('id', 'name', 'business_name', 'phone'),
                'issue_date' => $i->issue_date?->toDateString(), 'due_date' => $i->due_date?->toDateString(),
                'total' => (float) $i->total, 'amount_paid' => (float) $i->amount_paid, 'balance_due' => (float) $i->balance_due,
                'status' => $i->status, 'days_overdue' => $i->daysOverdue(),
            ])->sortByDesc('days_overdue')->values(),
        ];
    }

    public function messaging(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $range = fn ($q, string $column = 'created_at') => $q
            ->when($from, fn ($q) => $q->whereDate($column, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($column, '<=', $to));

        $outbound = $range(Message::query()->outbound());
        $counts = (clone $outbound)->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');
        $sent = (int) $counts->sum();
        $delivered = (int) ($counts['delivered'] ?? 0) + (int) ($counts['read'] ?? 0);
        $read = (int) ($counts['read'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $pct = fn (int $n) => $sent > 0 ? round($n / $sent * 100, 1) : 0.0;

        $templateUsage = (clone $outbound)->whereNotNull('template_id')
            ->selectRaw('template_id, count(*) as count')->groupBy('template_id')
            ->with('template:id,name')->get()
            ->map(fn ($r) => ['template_id' => $r->template_id, 'name' => $r->template?->name ?? 'Deleted template', 'count' => (int) $r->count])
            ->sortByDesc('count')->values();

        $bySource = (clone $outbound)->get(['metadata'])
            ->groupBy(fn ($m) => $m->metadata['source'] ?? 'manual')
            ->map->count();

        return [
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'messages_sent' => $sent,
            'delivered' => $delivered,
            'read' => $read,
            'failed' => $failed,
            'queued' => (int) ($counts['queued'] ?? 0) + (int) ($counts['sending'] ?? 0),
            'inbound' => $range(Message::query()->inbound())->count(),
            'delivery_rate' => $pct($delivered),
            'read_rate' => $pct($read),
            'failure_rate' => $pct($failed),
            'by_source' => $bySource,
            'template_usage' => $templateUsage,
            'monthly' => $this->monthlyMessages(6),
            'broadcasts' => $range(Broadcast::query(), 'created_at')->latest('id')->limit(10)->get()
                ->map(fn ($b) => array_merge($b->only('id', 'name', 'status', 'scheduled_at', 'completed_at'), $b->stats())),
        ];
    }

    private function monthlyMessages(int $months): array
    {
        $from = now()->subMonths($months - 1)->startOfMonth();
        $rows = Message::query()->outbound()->where('created_at', '>=', $from)->get(['created_at', 'status'])
            ->groupBy(fn ($m) => $m->created_at->format('Y-m'));

        $out = [];
        for ($cursor = $from->copy(); $cursor->lte(now()); $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $group = $rows[$key] ?? collect();
            $out[] = [
                'month' => $key,
                'label' => $cursor->format('M'),
                'sent' => $group->count(),
                'delivered' => $group->whereIn('status', ['delivered', 'read'])->count(),
                'read' => $group->where('status', 'read')->count(),
                'failed' => $group->where('status', 'failed')->count(),
            ];
        }

        return $out;
    }

    /**
     * A unified activity feed drawn from the audit log and recent records.
     */
    public function recentActivity(int $limit = 10): array
    {
        return \App\Models\AuditLog::query()->with('user:id,name')->latest('id')->limit($limit)->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'user' => $log->user?->name,
                'summary' => $this->describe($log),
                'at' => $log->created_at,
            ])->all();
    }

    private function describe(\App\Models\AuditLog $log): string
    {
        $new = $log->new_values ?? [];
        $old = $log->old_values ?? [];
        $label = str_replace(['.', '_'], ' ', $log->action);

        return match ($log->action) {
            'customer.created' => 'Customer ' . ($new['name'] ?? '') . ' added',
            'customer.deleted' => 'Customer ' . ($old['name'] ?? '') . ' deleted',
            'invoice.created' => 'Invoice ' . ($new['invoice_number'] ?? '') . ' created',
            'invoice.sent' => 'Invoice sent' . (!empty($new['via_whatsapp']) ? ' on WhatsApp' : ''),
            'invoice.cancelled' => 'Invoice cancelled',
            'payment.recorded' => 'Payment of ' . number_format((float) ($new['amount'] ?? 0), 2) . ' recorded',
            'payment.reversed' => 'Payment reversed',
            'template.created' => 'Template ' . ($new['name'] ?? '') . ' created',
            'user.role_changed' => 'Team member role changed to ' . ($new['role'] ?? ''),
            'user.invited' => 'Team member ' . ($new['email'] ?? '') . ' invited',
            default => ucfirst($label),
        };
    }
}
