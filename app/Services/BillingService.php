<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Business;
use App\Models\MessageUsage;
use App\Models\PaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\PaymentRequestReviewed;
use App\Tenancy\TenantManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Manual billing: a business uploads a transfer receipt for a plan, the
 * super admin approves (creating the subscription) or declines with a reason.
 * Plan resolution is: current subscription > active trial > nothing.
 */
class BillingService
{
    public const RECEIPT_DISK = 'local';

    public function __construct(private NotificationService $notifications)
    {
    }

    // ------------------------------------------------------------ resolution

    /**
     * The subscription that covers this moment, if any. Works with or
     * without a tenant context so jobs and admin screens can use it.
     */
    public function currentSubscription(Business $business): ?Subscription
    {
        return Subscription::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->current()
            ->orderByDesc('ends_at')
            ->with('plan')
            ->first();
    }

    /**
     * The plan a business is entitled to right now, or null when it has
     * neither a subscription nor a running trial.
     */
    public function planFor(Business $business): ?Plan
    {
        if ($subscription = $this->currentSubscription($business)) {
            return $subscription->plan;
        }

        if ($business->onTrial()) {
            $plan = Plan::trialDefault()->replicate();
            // Trials never get broadcasts regardless of the plan template.
            $plan->allows_broadcasts = false;
            $plan->marketing_quota = 0;
            $plan->name = 'Free trial';
            $plan->code = 'trial';
            return $plan;
        }

        return null;
    }

    /**
     * Entitlement state as a short word for banners and admin lists.
     */
    public function stateFor(Business $business): string
    {
        if ($business->status === 'suspended') {
            return 'suspended';
        }
        if ($this->currentSubscription($business)) {
            return 'active';
        }
        if ($business->onTrial()) {
            return 'trial';
        }
        return 'expired';
    }

    // ------------------------------------------------------------ requests

    /**
     * Store a receipt and open a pending payment request. Only one pending
     * request per business at a time.
     */
    public function submitPaymentRequest(Business $business, User $user, Plan $plan, UploadedFile $receipt, array $data = []): PaymentRequest
    {
        if (!$plan->is_active) {
            throw ValidationException::withMessages(['plan_id' => ['This plan is no longer available.']]);
        }

        if (PaymentRequest::withoutGlobalScopes()->where('business_id', $business->id)->pending()->exists()) {
            throw ValidationException::withMessages(['plan_id' => ['You already have a payment under review. Wait for it to be processed before submitting another.']]);
        }

        $path = $receipt->store("receipts/{$business->id}", self::RECEIPT_DISK);

        return TenantManager::runAs($business->id, fn () => PaymentRequest::create([
            'plan_id' => $plan->id,
            'submitted_by' => $user->id,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'receipt_path' => $path,
            'receipt_name' => $receipt->getClientOriginalName(),
            'receipt_mime' => $receipt->getClientMimeType(),
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'pending',
        ])->load('plan'));
    }

    /**
     * Approve a request: mark it, create the subscription (stacked after any
     * current one so no paid days are lost) and tell the business.
     */
    public function approve(PaymentRequest $request, User $admin): Subscription
    {
        if (!$request->isPending()) {
            throw ValidationException::withMessages(['status' => ['This payment request has already been reviewed.']]);
        }

        return DB::transaction(function () use ($request, $admin) {
            $business = Business::query()->findOrFail($request->business_id);

            $request->update(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);

            $subscription = $this->grant($business, $request->plan, $admin, $request);

            TenantManager::runAs($business->id, function () use ($request, $subscription) {
                $this->notifications->notify(
                    'payment_approved',
                    'Payment approved',
                    sprintf('Your %s plan is active until %s.', $subscription->plan->name, $subscription->ends_at->format('M d, Y')),
                    ['payment_request_id' => $request->id, 'subscription_id' => $subscription->id],
                );
            });
            $this->mailOwners($business, $request->fresh(['plan']));

            return $subscription;
        });
    }

    public function decline(PaymentRequest $request, User $admin, string $reason): PaymentRequest
    {
        if (!$request->isPending()) {
            throw ValidationException::withMessages(['status' => ['This payment request has already been reviewed.']]);
        }

        $request->update([
            'status' => 'declined',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'decline_reason' => $reason,
        ]);

        $business = Business::query()->findOrFail($request->business_id);
        TenantManager::runAs($business->id, function () use ($request, $reason) {
            $this->notifications->notify(
                'payment_declined',
                'Payment declined',
                sprintf('Your payment for the %s plan was declined: %s', $request->plan->name, $reason),
                ['payment_request_id' => $request->id],
            );
        });
        $this->mailOwners($business, $request->fresh(['plan']));

        return $request;
    }

    /**
     * Create a subscription directly (approval, comps, manual extensions).
     * A new period starts when the current one ends, otherwise now.
     */
    public function grant(Business $business, Plan $plan, ?User $admin = null, ?PaymentRequest $request = null, ?int $days = null, ?string $note = null): Subscription
    {
        $current = $this->currentSubscription($business);
        $latest = Subscription::withoutGlobalScopes()->where('business_id', $business->id)->where('status', 'active')->orderByDesc('ends_at')->first();

        $startsAt = ($latest && $latest->ends_at->isFuture()) ? $latest->ends_at->copy() : now();
        $endsAt = $startsAt->copy()->addDays($days ?? $plan->interval_days);

        $subscription = TenantManager::runAs($business->id, fn () => Subscription::create([
            'plan_id' => $plan->id,
            'payment_request_id' => $request?->id,
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'activated_by' => $admin?->id,
            'note' => $note,
        ]));

        if (!$current && in_array($business->status, ['trial', 'expired', 'active'], true)) {
            $business->update(['status' => 'active']);
        }

        return $subscription->load('plan');
    }

    /**
     * Expire subscriptions past their end date and downgrade businesses that
     * no longer have any entitlement. Returns [expired, notified].
     */
    public function runExpiry(): array
    {
        $expired = 0;
        $notified = 0;

        Subscription::withoutGlobalScopes()->where('status', 'active')->where('ends_at', '<=', now())
            ->with('plan')->orderBy('id')->chunkById(100, function ($rows) use (&$expired) {
                foreach ($rows as $subscription) {
                    $subscription->update(['status' => 'expired']);
                    $expired++;

                    $business = Business::query()->find($subscription->business_id);
                    if ($business && !$this->currentSubscription($business) && $business->status === 'active') {
                        $business->update(['status' => 'expired']);
                        TenantManager::runAs($business->id, fn () => $this->notifications->notify(
                            'subscription_expired',
                            'Subscription expired',
                            sprintf('Your %s plan ended on %s. Upload a payment receipt to keep sending messages.', $subscription->plan->name, $subscription->ends_at->format('M d, Y')),
                            ['subscription_id' => $subscription->id],
                        ));
                    }
                }
            });

        // One heads-up per subscription, seven days out.
        Subscription::withoutGlobalScopes()->current()->whereNull('expiry_notified_at')
            ->where('ends_at', '<=', now()->addDays(7))->with('plan')->orderBy('id')->chunkById(100, function ($rows) use (&$notified) {
                foreach ($rows as $subscription) {
                    $hasRenewal = Subscription::withoutGlobalScopes()->where('business_id', $subscription->business_id)->upcoming()->exists();
                    $subscription->update(['expiry_notified_at' => now()]);
                    if ($hasRenewal) {
                        continue;
                    }
                    TenantManager::runAs($subscription->business_id, fn () => $this->notifications->notify(
                        'subscription_expiring',
                        'Subscription ending soon',
                        sprintf('Your %s plan ends on %s. Upload a payment receipt to renew.', $subscription->plan->name, $subscription->ends_at->format('M d, Y')),
                        ['subscription_id' => $subscription->id],
                    ));
                    $notified++;
                }
            });

        // Trials that ran out without a subscription.
        Business::query()->where('status', 'trial')->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', now())
            ->orderBy('id')->chunkById(100, function ($rows) {
                foreach ($rows as $business) {
                    if (!$this->currentSubscription($business)) {
                        $business->update(['status' => 'expired']);
                    }
                }
            });

        return [$expired, $notified];
    }

    // ------------------------------------------------------------ presentation

    /**
     * Everything the billing screen needs in one payload.
     */
    public function summary(Business $business): array
    {
        $subscription = $this->currentSubscription($business);
        $plan = $this->planFor($business);
        $state = $this->stateFor($business);
        $period = MessageUsage::currentPeriod();

        $usage = MessageUsage::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('period_start', $period)
            ->get()
            ->keyBy('category');

        $pending = PaymentRequest::withoutGlobalScopes()->where('business_id', $business->id)->pending()->with('plan')->latest()->first();
        $lastDeclined = PaymentRequest::withoutGlobalScopes()->where('business_id', $business->id)->where('status', 'declined')->with('plan')->latest('reviewed_at')->first();
        $upcoming = Subscription::withoutGlobalScopes()->where('business_id', $business->id)->upcoming()->with('plan')->orderBy('starts_at')->first();

        return [
            'state' => $state,
            'status' => $business->status,
            'trial_ends_at' => $business->trial_ends_at,
            'trial_days_left' => $business->onTrial() ? max(0, (int) ceil(now()->diffInDays($business->trial_ends_at, false))) : 0,
            'plan' => $plan ? $this->presentPlan($plan) : null,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan' => $this->presentPlan($subscription->plan),
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'days_remaining' => $subscription->daysRemaining(),
            ] : null,
            'upcoming_subscription' => $upcoming ? [
                'id' => $upcoming->id,
                'plan' => $this->presentPlan($upcoming->plan),
                'starts_at' => $upcoming->starts_at,
                'ends_at' => $upcoming->ends_at,
            ] : null,
            'usage' => [
                'period_start' => $period->toDateString(),
                'utility' => $this->usageRow($usage->get('utility'), $plan?->quotaFor('utility')),
                'marketing' => $this->usageRow($usage->get('marketing'), $plan?->quotaFor('marketing')),
                'service' => $this->usageRow($usage->get('service'), null),
            ],
            'pending_request' => $pending,
            // Only surfaced until the business submits something newer.
            'last_declined_request' => ($lastDeclined && (!$pending || $lastDeclined->created_at->gt($pending->created_at))) ? $lastDeclined : null,
            'plans' => Plan::query()->active()->orderBy('sort_order')->orderBy('price')->get()->map(fn (Plan $p) => $this->presentPlan($p))->values(),
            'bank_accounts' => BankAccount::query()->active()->orderBy('sort_order')->get(),
        ];
    }

    public function presentPlan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => (float) $plan->price,
            'currency' => $plan->currency,
            'interval_days' => $plan->interval_days,
            'utility_quota' => $plan->utility_quota,
            'marketing_quota' => $plan->marketing_quota,
            'allows_broadcasts' => (bool) $plan->allows_broadcasts,
            'max_team_members' => $plan->max_team_members,
            'is_trial_default' => (bool) $plan->is_trial_default,
            'is_active' => (bool) $plan->is_active,
            'sort_order' => $plan->sort_order,
        ];
    }

    public function receiptDisk()
    {
        return Storage::disk(self::RECEIPT_DISK);
    }

    private function usageRow(?MessageUsage $row, ?int $quota): array
    {
        $sent = $row?->sent_count ?? 0;

        return [
            'sent' => $sent,
            'failed' => $row?->failed_count ?? 0,
            'quota' => $quota,
            'remaining' => $quota === null ? null : max(0, $quota - $sent),
        ];
    }

    private function mailOwners(Business $business, PaymentRequest $request): void
    {
        $business->users()->wherePivot('role', 'owner')->wherePivot('status', 'active')->get()
            ->each(fn (User $owner) => $owner->notify(new PaymentRequestReviewed($request)));
    }
}
