<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Business;
use App\Models\Message;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\DB;

class DashboardController extends ApiController
{
    public function index()
    {
        $monthStart = now()->startOfMonth();

        $businessesByStatus = Business::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $data = [
            'businesses' => [
                'total' => Business::query()->count(),
                'trial' => (int) ($businessesByStatus['trial'] ?? 0),
                'active' => (int) ($businessesByStatus['active'] ?? 0),
                'expired' => (int) ($businessesByStatus['expired'] ?? 0),
                'suspended' => (int) ($businessesByStatus['suspended'] ?? 0),
                'new_this_month' => Business::query()->where('created_at', '>=', $monthStart)->count(),
            ],
            'users' => ['total' => User::query()->count()],
            'payments' => [
                'pending' => PaymentRequest::withoutGlobalScopes()->pending()->count(),
                'approved_this_month' => PaymentRequest::withoutGlobalScopes()->where('status', 'approved')->where('reviewed_at', '>=', $monthStart)->count(),
                'revenue_this_month' => (float) PaymentRequest::withoutGlobalScopes()->where('status', 'approved')->where('reviewed_at', '>=', $monthStart)->sum('amount'),
                'revenue_total' => (float) PaymentRequest::withoutGlobalScopes()->where('status', 'approved')->sum('amount'),
            ],
            'subscriptions' => [
                'current' => Subscription::withoutGlobalScopes()->current()->count(),
                'expiring_7d' => Subscription::withoutGlobalScopes()->current()->where('ends_at', '<=', now()->addDays(7))->count(),
            ],
            'whatsapp' => [
                'connections_active' => WhatsAppConnection::withoutGlobalScopes()->where('status', 'active')->count(),
                'connections_error' => WhatsAppConnection::withoutGlobalScopes()->where('status', 'error')->count(),
            ],
            'messages' => [
                'sent_today' => Message::withoutGlobalScopes()->outbound()->whereIn('status', ['sent', 'delivered', 'read'])->where('created_at', '>=', now()->startOfDay())->count(),
                'sent_this_month' => Message::withoutGlobalScopes()->outbound()->whereIn('status', ['sent', 'delivered', 'read'])->where('created_at', '>=', $monthStart)->count(),
                'failed_this_month' => Message::withoutGlobalScopes()->outbound()->where('status', 'failed')->where('created_at', '>=', $monthStart)->count(),
                'queued' => Message::withoutGlobalScopes()->outbound()->whereIn('status', ['queued', 'sending'])->count(),
            ],
            'queue' => [
                'pending_jobs' => (int) DB::table('jobs')->count(),
                'failed_jobs' => (int) DB::table('failed_jobs')->count(),
            ],
            'recent_payment_requests' => PaymentRequest::withoutGlobalScopes()->pending()->with(['plan:id,code,name,price,currency', 'business:id,name,slug'])
                ->oldest()->limit(5)->get(),
        ];

        return $this->successResponse('Admin dashboard retrieved successfully.', $data);
    }
}
