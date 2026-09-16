<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Business;
use App\Models\Message;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\PaymentRequestReviewed;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BillingAndAdminTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    /** A business with no subscription, only the trial flag. */
    private function trialMembership(int $trialDaysLeft = 14): array
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'Trial Co', 'slug' => 'trial-' . uniqid(), 'currency' => 'NGN', 'status' => 'trial',
            'trial_ends_at' => now()->addDays($trialDaysLeft),
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);
        $this->plan('starter', ['price' => 5000, 'utility_quota' => 3, 'marketing_quota' => 0, 'allows_broadcasts' => false, 'is_trial_default' => true, 'sort_order' => 1]);
        $this->plan('business', ['price' => 15000, 'utility_quota' => 3000, 'marketing_quota' => 1000, 'allows_broadcasts' => true, 'sort_order' => 2]);

        return [$user, $business];
    }

    // ------------------------------------------------------------ registration

    public function test_registration_starts_a_fourteen_day_trial(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'business_name' => 'New Shop',
        ])->assertCreated();

        $business = Business::query()->findOrFail($res->json('data.user.business.id'));
        $this->assertSame('trial', $business->status);
        $this->assertTrue($business->trial_ends_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertFalse($res->json('data.user.is_super_admin'));
    }

    // ------------------------------------------------------------ user billing

    public function test_billing_summary_reflects_trial_and_plans(): void
    {
        [$user, $business] = $this->trialMembership();

        $res = $this->actingAs($user)->getJson('/api/v1/billing')->assertOk();
        $res->assertJsonPath('data.state', 'trial')
            ->assertJsonPath('data.plan.code', 'trial')
            ->assertJsonPath('data.plan.allows_broadcasts', false)
            ->assertJsonPath('data.usage.utility.quota', 3)
            ->assertJsonPath('data.subscription', null)
            ->assertJsonCount(2, 'data.plans');

        $this->actingAs($user)->getJson('/api/v1/business')->assertOk()
            ->assertJsonPath('data.billing.state', 'trial')->assertJsonPath('data.billing.trial_days_left', 14);
    }

    public function test_owner_uploads_receipt_and_only_one_pending_request_is_allowed(): void
    {
        [$user, $business] = $this->trialMembership();
        $plan = $this->plan('business');

        $res = $this->actingAs($user)->post('/api/v1/billing/payment-requests', [
            'plan_id' => $plan->id,
            'receipt' => UploadedFile::fake()->create('transfer.png', 50, 'image/png'),
            'reference' => 'TRF-001',
            'amount_claimed' => 14500,
        ], ['Accept' => 'application/json'])->assertCreated();

        $res->assertJsonPath('data.status', 'pending')->assertJsonPath('data.amount', '15000.00')->assertJsonPath('data.amount_claimed', '14500.00')
            ->assertJsonPath('data.method', 'manual_receipt')->assertJsonMissingPath('data.receipt_path');
        $request = PaymentRequest::withoutGlobalScopes()->findOrFail($res->json('data.id'));
        Storage::disk('local')->assertExists($request->receipt_path);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'payment_request.submitted']);

        $this->actingAs($user)->post('/api/v1/billing/payment-requests', [
            'plan_id' => $plan->id, 'receipt' => UploadedFile::fake()->create('again.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('plan_id');

        // Wrong file types are rejected.
        $this->actingAs($user)->post('/api/v1/billing/payment-requests', [
            'plan_id' => $plan->id, 'receipt' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('receipt');

        // The owner can fetch their own receipt; the pending request shows in the summary.
        $this->actingAs($user)->get("/api/v1/billing/payment-requests/{$request->id}/receipt")->assertOk();
        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.pending_request.id', $request->id);
    }

    public function test_managers_cannot_submit_payment_requests(): void
    {
        [$owner, $business] = $this->trialMembership();
        $manager = User::factory()->create();
        $manager->businesses()->attach($business->id, ['role' => 'manager', 'status' => 'active']);

        $this->actingAs($manager)->post('/api/v1/billing/payment-requests', [
            'plan_id' => $this->plan('business')->id, 'receipt' => UploadedFile::fake()->create('r.png', 50, 'image/png'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    // ------------------------------------------------------------ admin access

    public function test_admin_routes_require_super_admin(): void
    {
        [$user] = $this->membership();

        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
        $this->actingAs($user)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/admin/payment-requests')->assertForbidden();

        $admin = $this->superAdmin();
        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonStructure(['data' => ['businesses', 'payments', 'messages', 'queue']]);
        $this->actingAs($admin)->getJson('/api/v1/auth/user')->assertJsonPath('data.is_super_admin', true);
    }

    public function test_admin_login_only_accepts_the_configured_super_admin(): void
    {
        config(['billing.super_admin.email' => 'admin@example.com', 'billing.super_admin.password' => 'console-secret']);
        $admin = User::factory()->create(['email' => 'admin@example.com', 'password' => 'console-secret']);
        $admin->forceFill(['is_super_admin' => true])->save();
        [$owner] = $this->membership();
        $otherFlagged = $this->superAdmin(); // flagged in the DB, but not the configured identity

        $incorrect = fn (array $body) => $this->postJson('/api/v1/auth/admin/login', $body)
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        // Wrong password for the configured email.
        $incorrect(['email' => 'admin@example.com', 'password' => 'wrong-secret']);
        // A business owner with a valid database password.
        $incorrect(['email' => $owner->email, 'password' => 'password']);
        // Another user flagged is_super_admin with a valid database password.
        $incorrect(['email' => $otherFlagged->email, 'password' => 'password']);
        // The configured password used with someone else's email.
        $incorrect(['email' => $owner->email, 'password' => 'console-secret']);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Only the configured pair signs in (email is case-insensitive).
        $res = $this->postJson('/api/v1/auth/admin/login', ['email' => 'Admin@Example.com', 'password' => 'console-secret'])->assertOk();
        $res->assertJsonPath('data.user.id', $admin->id)->assertJsonPath('data.user.is_super_admin', true);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $admin->id, 'name' => 'admin-console']);
        $this->withToken($res->json('data.token'))->getJson('/api/v1/admin/dashboard')->assertOk();

        // A super-admin token from the *business* login cannot reach the console API.
        $workspaceAdmin = $this->superAdmin();
        $workspaceAdmin->businesses()->attach($this->membership()[1]->id, ['role' => 'owner', 'status' => 'active']);
        $businessToken = $this->postJson('/api/v1/auth/login', ['email' => $workspaceAdmin->email, 'password' => 'password'])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->withToken($businessToken)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->withToken($businessToken)->getJson('/api/v1/business')->assertOk();
        $this->app['auth']->forgetGuards();

        // Configured credentials without a matching super admin row are refused.
        $admin->forceFill(['is_super_admin' => false])->save();
        $this->postJson('/api/v1/auth/admin/login', ['email' => 'admin@example.com', 'password' => 'console-secret'])
            ->assertForbidden()->assertJsonPath('message', 'This account does not have administrator access.');

        // Nothing configured means nobody can use the console endpoint.
        config(['billing.super_admin.email' => null, 'billing.super_admin.password' => null]);
        $incorrect(['email' => 'admin@example.com', 'password' => 'console-secret']);

        // A console-only admin (no business) is sent to the admin login by the business login.
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'console-secret'])
            ->assertForbidden()->assertJsonPath('message', 'This is an administrator account. Sign in at /admin/login instead.');
    }

    public function test_super_admin_seeder_creates_the_console_account(): void
    {
        config(['billing.super_admin.email' => 'console@example.com', 'billing.super_admin.password' => 'console-secret']);
        $this->artisan('db:seed', ['--class' => 'SuperAdminSeeder'])->assertSuccessful();

        $user = User::query()->where('email', 'console@example.com')->firstOrFail();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertNotNull($user->email_verified_at);
        $this->postJson('/api/v1/auth/admin/login', ['email' => 'console@example.com', 'password' => 'console-secret'])->assertOk();
    }

    public function test_super_admin_cannot_be_granted_through_registration_or_profile(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'business_name' => 'Sneaky Ltd', 'is_super_admin' => true,
        ])->assertCreated();

        $this->assertFalse(User::query()->findOrFail($res->json('data.user.id'))->isSuperAdmin());
    }

    // ------------------------------------------------------------ approve / decline

    public function test_admin_approves_receipt_which_activates_the_subscription(): void
    {
        [$user, $business] = $this->trialMembership();
        $plan = $this->plan('business');
        $admin = $this->superAdmin();

        $requestId = $this->actingAs($user)->post('/api/v1/billing/payment-requests', [
            'plan_id' => $plan->id, 'receipt' => UploadedFile::fake()->create('r.png', 50, 'image/png'),
        ], ['Accept' => 'application/json'])->json('data.id');

        $this->actingAs($admin)->getJson('/api/v1/admin/payment-requests')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.business.name', 'Trial Co');
        $this->actingAs($admin)->getJson("/api/v1/admin/payment-requests/{$requestId}")->assertOk()
            ->assertJsonPath('data.business.billing.state', 'trial')->assertJsonPath('data.amount_mismatch', false);
        $this->actingAs($admin)->get("/api/v1/admin/payment-requests/{$requestId}/receipt")->assertOk();

        $res = $this->actingAs($admin)->postJson("/api/v1/admin/payment-requests/{$requestId}/approve")->assertOk();
        $res->assertJsonPath('data.payment_request.status', 'approved')
            ->assertJsonPath('data.payment_request.reviewer.id', $admin->id)
            ->assertJsonPath('data.subscription.plan.code', 'business');

        $subscription = Subscription::withoutGlobalScopes()->where('business_id', $business->id)->firstOrFail();
        $this->assertTrue($subscription->isCurrent());
        $this->assertTrue($subscription->ends_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertSame('active', $business->fresh()->status);
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'payment_approved']);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'payment_request.approved', 'user_id' => $admin->id]);
        Notification::assertSentTo($user, PaymentRequestReviewed::class);

        // The business now sees its plan; approving twice is rejected.
        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.plan.code', 'business')->assertJsonPath('data.subscription.days_remaining', 30);
        $this->actingAs($admin)->postJson("/api/v1/admin/payment-requests/{$requestId}/approve")->assertUnprocessable();
    }

    public function test_renewal_stacks_after_the_current_subscription(): void
    {
        [$user, $business] = $this->trialMembership();
        $plan = $this->plan('business');
        $admin = $this->superAdmin();
        $current = $this->subscribe($business, $plan, 10);

        $requestId = $this->actingAs($user)->post('/api/v1/billing/payment-requests', [
            'plan_id' => $plan->id, 'receipt' => UploadedFile::fake()->create('r.png', 50, 'image/png'),
        ], ['Accept' => 'application/json'])->json('data.id');
        $this->actingAs($admin)->postJson("/api/v1/admin/payment-requests/{$requestId}/approve")->assertOk();

        $next = Subscription::withoutGlobalScopes()->where('business_id', $business->id)->whereKeyNot($current->id)->firstOrFail();
        $this->assertSame($current->ends_at->timestamp, $next->starts_at->timestamp);
        $this->assertSame($current->ends_at->copy()->addDays(30)->timestamp, $next->ends_at->timestamp);

        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.upcoming_subscription.id', $next->id);
    }

    public function test_admin_declines_with_a_reason_and_the_business_can_resubmit(): void
    {
        [$user, $business] = $this->trialMembership();
        $plan = $this->plan('business');
        $admin = $this->superAdmin();

        $requestId = $this->actingAs($user)->post('/api/v1/billing/payment-requests', [
            'plan_id' => $plan->id, 'receipt' => UploadedFile::fake()->create('r.png', 50, 'image/png'),
        ], ['Accept' => 'application/json'])->json('data.id');

        $this->actingAs($admin)->postJson("/api/v1/admin/payment-requests/{$requestId}/decline", ['reason' => ''])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/v1/admin/payment-requests/{$requestId}/decline", ['reason' => 'Amount on the receipt does not match the plan price.'])
            ->assertOk()->assertJsonPath('data.status', 'declined')->assertJsonPath('data.decline_reason', 'Amount on the receipt does not match the plan price.');

        $this->assertSame(0, Subscription::withoutGlobalScopes()->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'payment_declined']);
        Notification::assertSentTo($user, PaymentRequestReviewed::class);

        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.state', 'trial')
            ->assertJsonPath('data.pending_request', null)
            ->assertJsonPath('data.last_declined_request.decline_reason', 'Amount on the receipt does not match the plan price.');

        // A new upload is allowed after a decline.
        $this->actingAs($user)->post('/api/v1/billing/payment-requests', [
            'plan_id' => $plan->id, 'receipt' => UploadedFile::fake()->create('r2.png', 50, 'image/png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.last_declined_request', null);
    }

    // ------------------------------------------------------------ enforcement

    public function test_trial_quota_blocks_sending_and_broadcasts(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        [$user, $business] = $this->trialMembership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business);

        // Starter trial allows 3 utility messages per month.
        for ($i = 0; $i < 3; $i++) {
            $id = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => "Hi {$i}"])->assertCreated()->json('data.id');
            $message = Message::withoutGlobalScopes()->findOrFail($id);
            $this->assertSame('utility', $message->billing_category);
            // Simulate delivery so the counter moves.
            $this->asTenant($business->id, fn () => app(\App\Services\QuotaService::class)->recordDelivered($message));
        }

        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'One too many'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'quota_exhausted']);
        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.usage.utility.sent', 3)->assertJsonPath('data.usage.utility.remaining', 0);

        // Broadcasts are locked during the trial regardless of quota.
        $this->actingAs($user)->postJson('/api/v1/broadcasts', ['name' => 'Promo', 'content' => 'Sale!', 'customer_ids' => [$customer->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('plan');
    }

    public function test_replies_inside_the_service_window_are_free(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        [$user, $business] = $this->trialMembership();
        $connection = $this->connectionFor($business);
        $customer = $this->customerFor($business);

        // Use the whole utility quota first.
        $this->asTenant($business->id, fn () => \App\Models\MessageUsage::create(['period_start' => \App\Models\MessageUsage::periodStartFor($business, null), 'category' => 'utility', 'sent_count' => 3]));
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Blocked'])->assertUnprocessable();

        // A customer message opens the 24h window; the reply is a free service message.
        $this->asTenant($business->id, fn () => app(\App\Services\WhatsAppService::class)->handleIncoming($connection, ['from' => '2348030000001', 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'Hello?']]));
        $id = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Reply'])->assertCreated()->json('data.id');
        $this->assertSame('service', Message::withoutGlobalScopes()->findOrFail($id)->billing_category);
    }

    public function test_expired_trial_without_subscription_cannot_send(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        [$user, $business] = $this->trialMembership(-1);
        $this->connectionFor($business);
        $customer = $this->customerFor($business);

        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Hi'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.state', 'expired')->assertJsonPath('data.plan', null);

        // Paid plan with broadcasts unlocks everything.
        $this->subscribe($business, $this->plan('business'));
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Hi'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/broadcasts', ['name' => 'Promo', 'content' => 'Sale!', 'customer_ids' => [$customer->id]])->assertCreated();
    }

    public function test_expiry_command_downgrades_businesses_and_warns_before_the_end(): void
    {
        [$user, $business] = $this->trialMembership(-1);
        $plan = $this->plan('business');
        $ending = $this->asTenant($business->id, fn () => Subscription::create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDays(40), 'ends_at' => now()->subDay()]));
        $business->update(['status' => 'active']);

        [$other, $otherBusiness] = $this->trialMembership();
        $soon = $this->asTenant($otherBusiness->id, fn () => Subscription::create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDays(25), 'ends_at' => now()->addDays(3)]));

        $this->artisan('subscriptions:expire')->assertSuccessful();

        // Ended period -> grace first (full access), then expired once grace runs out.
        $this->assertSame('grace', $ending->fresh()->status);
        $this->assertSame('active', $business->fresh()->status);
        $ending->update(['grace_ends_at' => now()->subMinute()]);
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame('expired', $ending->fresh()->status);
        $this->assertSame('expired', $business->fresh()->status);
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'subscription_expired']);
        $this->assertDatabaseHas('notifications', ['business_id' => $otherBusiness->id, 'type' => 'subscription_expiring']);
        $this->assertNotNull($soon->fresh()->expiry_notified_at);

        // Further runs do not repeat the 7-day warning.
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame(1, \App\Models\Notification::withoutGlobalScopes()->where('business_id', $otherBusiness->id)->where('type', 'subscription_expiring')->count());
    }

    // ------------------------------------------------------------ admin management

    public function test_admin_manages_plans_bank_accounts_and_businesses(): void
    {
        $admin = $this->superAdmin();
        [$user, $business] = $this->trialMembership();

        // Plans
        $planId = $this->actingAs($admin)->postJson('/api/v1/admin/plans', [
            'code' => 'enterprise', 'name' => 'Enterprise', 'price' => 100000, 'currency' => 'NGN', 'interval_days' => 30,
            'utility_quota' => null, 'marketing_quota' => 20000, 'allows_broadcasts' => true,
        ])->assertCreated()->json('data.id');
        $this->actingAs($admin)->putJson("/api/v1/admin/plans/{$planId}", [
            'code' => 'enterprise', 'name' => 'Enterprise+', 'price' => 120000, 'currency' => 'NGN', 'interval_days' => 30,
            'allows_broadcasts' => true, 'marketing_quota' => 25000,
        ])->assertOk()->assertJsonPath('data.name', 'Enterprise+');
        $this->actingAs($admin)->getJson('/api/v1/admin/plans')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($admin)->deleteJson("/api/v1/admin/plans/{$planId}")->assertOk();
        $this->assertDatabaseMissing('plans', ['id' => $planId]);

        // Bank accounts show up for businesses.
        $accountId = $this->actingAs($admin)->postJson('/api/v1/admin/bank-accounts', [
            'bank_name' => 'GTBank', 'account_name' => 'FlowCRM Ltd', 'account_number' => '0123456789', 'currency' => 'NGN',
        ])->assertCreated()->json('data.id');
        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.bank_accounts.0.account_number', '0123456789');
        $this->actingAs($admin)->putJson("/api/v1/admin/bank-accounts/{$accountId}", [
            'bank_name' => 'GTBank', 'account_name' => 'FlowCRM Ltd', 'account_number' => '0123456789', 'currency' => 'NGN', 'is_active' => false,
        ])->assertOk();
        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonCount(0, 'data.bank_accounts');

        // Businesses: list, detail, grant, suspend.
        $this->actingAs($admin)->getJson('/api/v1/admin/businesses?search=Trial')->assertOk()
            ->assertJsonPath('data.data.0.name', 'Trial Co')->assertJsonPath('data.data.0.billing_state', 'trial')->assertJsonPath('data.data.0.owner.email', $user->email);
        $this->actingAs($admin)->getJson("/api/v1/admin/businesses/{$business->id}")->assertOk()
            ->assertJsonPath('data.billing.state', 'trial')->assertJsonCount(1, 'data.members');

        $businessPlan = $this->plan('business');
        $this->actingAs($admin)->postJson("/api/v1/admin/businesses/{$business->id}/subscriptions", ['plan_id' => $businessPlan->id, 'days' => 60, 'note' => 'Launch partner'])
            ->assertCreated()->assertJsonPath('data.plan.code', 'business');
        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.state', 'active')->assertJsonPath('data.subscription.days_remaining', 60);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'subscription.granted']);

        $this->actingAs($admin)->postJson("/api/v1/admin/businesses/{$business->id}/suspend", ['reason' => 'Spam reports'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.state', 'suspended');
        $this->actingAs($admin)->postJson("/api/v1/admin/businesses/{$business->id}/unsuspend")->assertOk()->assertJsonPath('data.status', 'active');

        $this->actingAs($admin)->postJson("/api/v1/admin/businesses/{$business->id}/extend-trial", ['days' => 7])->assertOk();

        // Users
        $this->actingAs($admin)->getJson('/api/v1/admin/users?search=' . urlencode($user->email))->assertOk()->assertJsonPath('data.data.0.id', $user->id);
        $this->actingAs($admin)->putJson("/api/v1/admin/users/{$user->id}/super-admin", ['is_super_admin' => true])->assertOk()->assertJsonPath('data.is_super_admin', true);
        $this->actingAs($admin)->putJson("/api/v1/admin/users/{$admin->id}/super-admin", ['is_super_admin' => false])->assertUnprocessable();

        // System views
        $this->actingAs($admin)->getJson('/api/v1/admin/whatsapp/connections')->assertOk();
        $this->actingAs($admin)->getJson("/api/v1/admin/audit-logs?business_id={$business->id}")->assertOk()->assertJsonPath('data.data.0.business.id', $business->id);
        $this->actingAs($admin)->getJson('/api/v1/admin/system')->assertOk()->assertJsonStructure(['data' => ['app', 'queue', 'whatsapp']]);
    }

    public function test_admin_can_impersonate_a_business_owner(): void
    {
        $admin = $this->superAdmin();
        [$owner, $business] = $this->trialMembership();

        $res = $this->actingAs($admin)->postJson("/api/v1/admin/businesses/{$business->id}/impersonate")->assertOk();
        $res->assertJsonPath('data.user.id', $owner->id)->assertJsonPath('data.impersonating.business_id', $business->id);
        $token = $res->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'admin.impersonated']);

        // The token works as the owner against tenant endpoints.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/business')->assertOk()->assertJsonPath('data.id', $business->id);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $owner->id, 'name' => 'impersonation:' . $admin->id]);
    }

    public function test_grant_command_promotes_a_user(): void
    {
        $user = User::factory()->create();

        $this->artisan('admin:grant', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->isSuperAdmin());

        $this->artisan('admin:grant', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->isSuperAdmin());

        $this->artisan('admin:grant', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
