<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageUsage;
use App\Models\PaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\AuditLogger;
use App\Services\BillingService;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessController extends ApiController
{
    public function __construct(private BillingService $billing, private AuditLogger $audit)
    {
    }

    public function index(Request $request)
    {
        $query = Business::query()
            ->withCount(['customers', 'invoices'])
            ->with(['users' => fn ($q) => $q->wherePivot('role', 'owner')->select('users.id', 'users.name', 'users.email')]);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('users', fn ($u) => $u->where('email', 'like', "%{$search}%"));
            });
        }

        $sort = in_array($request->query('sort'), ['name', 'created_at', 'status'], true) ? $request->query('sort') : 'created_at';
        $query->orderBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc');

        $page = $query->paginate(min((int) $request->query('per_page', 20), 100));

        // Enrich each row with its entitlement and WhatsApp state.
        $ids = $page->getCollection()->pluck('id');
        $subscriptions = Subscription::withoutGlobalScopes()->whereIn('business_id', $ids)->current()->with('plan:id,code,name')->get()->keyBy('business_id');
        $connections = WhatsAppConnection::withoutGlobalScopes()->whereIn('business_id', $ids)->where('status', 'active')->get()->groupBy('business_id');
        $pending = PaymentRequest::withoutGlobalScopes()->whereIn('business_id', $ids)->pending()->get()->groupBy('business_id');

        $page->getCollection()->transform(function (Business $business) use ($subscriptions, $connections, $pending) {
            $data = $business->toArray();
            $subscription = $subscriptions->get($business->id);
            $data['owner'] = $business->users->first() ? ['id' => $business->users->first()->id, 'name' => $business->users->first()->name, 'email' => $business->users->first()->email] : null;
            unset($data['users']);
            $data['billing_state'] = $this->billing->stateFor($business);
            $data['plan_name'] = $subscription?->plan?->name ?? ($business->onTrial() ? 'Free trial' : null);
            $data['subscription_ends_at'] = $subscription?->ends_at;
            $data['whatsapp_connected'] = $connections->has($business->id);
            $data['pending_payment_requests'] = $pending->get($business->id)?->count() ?? 0;
            return $data;
        });

        return $this->successResponse('Businesses retrieved successfully.', $page);
    }

    public function show(int $business)
    {
        $record = Business::query()->withCount(['customers', 'invoices'])->findOrFail($business);

        $data = $record->toArray();
        $data['members'] = $record->users()->select('users.id', 'users.name', 'users.email', 'users.email_verified_at')->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'email_verified_at' => $u->email_verified_at, 'role' => $u->pivot->role, 'membership_status' => $u->pivot->status]);
        $data['billing'] = $this->billing->summary($record);
        $data['subscriptions'] = Subscription::withoutGlobalScopes()->where('business_id', $record->id)->with(['plan:id,code,name,price,currency', 'activator:id,name'])->latest('starts_at')->limit(20)->get();
        $data['payment_requests'] = PaymentRequest::withoutGlobalScopes()->where('business_id', $record->id)->with(['plan:id,code,name', 'reviewer:id,name'])->latest()->limit(20)->get();
        $data['connections'] = WhatsAppConnection::withoutGlobalScopes()->where('business_id', $record->id)->latest()->get();
        $data['usage_history'] = MessageUsage::withoutGlobalScopes()->where('business_id', $record->id)->orderByDesc('period_start')->limit(18)->get();
        $data['stats'] = [
            'messages_sent_total' => Message::withoutGlobalScopes()->where('business_id', $record->id)->outbound()->whereIn('status', ['sent', 'delivered', 'read'])->count(),
            'messages_failed_total' => Message::withoutGlobalScopes()->where('business_id', $record->id)->outbound()->where('status', 'failed')->count(),
            'customers' => Customer::withoutGlobalScopes()->where('business_id', $record->id)->count(),
            'invoices' => Invoice::withoutGlobalScopes()->where('business_id', $record->id)->count(),
            'last_message_at' => Message::withoutGlobalScopes()->where('business_id', $record->id)->max('created_at'),
        ];
        $data['plans'] = Plan::query()->orderBy('sort_order')->get()->map(fn (Plan $p) => $this->billing->presentPlan($p))->values();

        return $this->successResponse('Business retrieved successfully.', $data);
    }

    public function suspend(Request $request, int $business)
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $record = Business::query()->findOrFail($business);

        $record->update(['status' => 'suspended']);
        TenantManager::runAs($record->id, fn () => $this->audit->log('business.suspended', $record, null, null, ['reason' => $validated['reason'] ?? null]));

        return $this->successResponse('Business suspended.', $record->fresh());
    }

    public function unsuspend(int $business)
    {
        $record = Business::query()->findOrFail($business);
        if ($record->status !== 'suspended') {
            return $this->errorResponse('This business is not suspended.', 422);
        }

        $record->update(['status' => $this->billing->currentSubscription($record) ? 'active' : ($record->onTrial() ? 'trial' : 'expired')]);
        TenantManager::runAs($record->id, fn () => $this->audit->log('business.unsuspended', $record));

        return $this->successResponse('Business reactivated.', $record->fresh());
    }

    /**
     * Grant a plan without a payment (comps, extensions, corrections).
     */
    public function grantSubscription(Request $request, int $business)
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $record = Business::query()->findOrFail($business);
        $plan = Plan::query()->findOrFail($validated['plan_id']);

        $subscription = $this->billing->grant($record, $plan, $request->user(), null, $validated['days'] ?? null, $validated['note']);

        TenantManager::runAs($record->id, fn () => $this->audit->log('subscription.granted', $subscription, null, null, [
            'plan' => $plan->code, 'days' => $validated['days'] ?? $plan->interval_days, 'note' => $validated['note'],
        ]));

        return $this->successResponse('Subscription granted.', $subscription, 201);
    }

    public function extendTrial(Request $request, int $business)
    {
        $validated = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);
        $record = Business::query()->findOrFail($business);

        $from = $record->trial_ends_at && $record->trial_ends_at->isFuture() ? $record->trial_ends_at : now();
        $record->update(['trial_ends_at' => $from->copy()->addDays($validated['days']), 'status' => $record->status === 'expired' ? 'trial' : $record->status]);

        TenantManager::runAs($record->id, fn () => $this->audit->log('trial.extended', $record, null, null, ['days' => $validated['days'], 'trial_ends_at' => $record->trial_ends_at]));

        return $this->successResponse('Trial extended.', $record->fresh());
    }

    /**
     * Issue a token that logs the admin in as the business owner. The token
     * is named so it can be told apart, and the action is audited in the
     * business's own trail.
     */
    public function impersonate(Request $request, int $business)
    {
        $record = Business::query()->findOrFail($business);
        $owner = $record->users()->wherePivot('role', 'owner')->wherePivot('status', 'active')->first()
            ?? $record->users()->wherePivot('status', 'active')->first();

        if (!$owner) {
            return $this->errorResponse('This business has no active members to impersonate.', 422);
        }

        $token = $owner->createToken('impersonation:' . $request->user()->id, ['*'], now()->addHours(2))->plainTextToken;

        TenantManager::runAs($record->id, fn () => $this->audit->log('admin.impersonated', $record, null, null, [
            'admin_id' => $request->user()->id, 'admin_email' => $request->user()->email, 'as_user_id' => $owner->id,
        ]));

        $payload = app(AuthController::class)->authPayload($owner, $token);
        $payload['impersonating'] = ['business_id' => $record->id, 'business_name' => $record->name, 'admin_id' => $request->user()->id];

        return $this->successResponse('Impersonation session created.', $payload);
    }
}
