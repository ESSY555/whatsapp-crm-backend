<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Business;
use App\Models\Message;
use App\Models\Notification;
use App\Models\PaymentSetting;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\NotificationService;
use App\Services\WalletService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TenantWalletTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        PaymentSetting::current(); // Seed default settings
    }

    private function setupTenant(int $utilityQuota = 0, float $walletBalance = 0.0): array
    {
        Queue::fake([SendWhatsAppMessage::class]);

        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'Wallet Corp',
            'slug' => 'wallet-' . uniqid(),
            'currency' => 'NGN',
            'status' => 'active',
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);

        if ($utilityQuota > 0) {
            $plan = $this->plan('starter', [
                'price' => 15000,
                'utility_quota' => $utilityQuota,
                'marketing_quota' => 0,
                'allows_broadcasts' => false,
                'is_trial_default' => true,
            ]);
            $this->subscribe($business, $plan);
        }

        $this->connectionFor($business);

        if ($walletBalance > 0) {
            app(WalletService::class)->credit($business, $walletBalance, 'Initial balance');
        }

        return [$user, $business];
    }

    private function webhookStatus(Business $business, Message $message, string $status): void
    {
        $message->update(['whatsapp_message_id' => $message->whatsapp_message_id ?? "wamid.{$message->id}", 'status' => 'sent']);
        $this->asTenant($business->id, fn () => app(WhatsAppService::class)->applyStatus($message->whatsapp_message_id, $status));
    }

    public function test_quota_first_then_wallet_deduction(): void
    {
        [$user, $business] = $this->setupTenant(utilityQuota: 1, walletBalance: 100.00);
        $customer = $this->customerFor($business);

        // Message 1: should use the 1 included message in the plan quota
        $res1 = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'body' => 'Quota message',
        ])->assertCreated();

        $msg1 = Message::withoutGlobalScopes()->findOrFail($res1->json('data.id'));
        $this->assertSame('subscription', $msg1->billing_source);
        $this->assertNull($msg1->unit_price);

        // Wallet was NOT debited
        $wallet = Wallet::withoutGlobalScopes()->where('business_id', $business->id)->first();
        $this->assertEquals(100.00, (float) $wallet->balance);

        // Deliver message 1
        $this->webhookStatus($business, $msg1, 'delivered');

        // Message 2: plan quota is exhausted, should debit from wallet
        $res2 = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'body' => 'Wallet message',
        ])->assertCreated();

        $msg2 = Message::withoutGlobalScopes()->findOrFail($res2->json('data.id'));
        $this->assertSame('wallet', $msg2->billing_source);
        $this->assertSame(30.30, (float) $msg2->unit_price);

        // Wallet WAS debited by 30.30
        $wallet->refresh();
        $this->assertEquals(69.70, (float) $wallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'business_id' => $business->id,
            'type' => 'debit',
            'amount' => 30.30,
            'balance_after' => 69.70,
            'reference' => "msg_{$msg2->id}",
        ]);
    }

    public function test_insufficient_quota_and_insufficient_wallet_blocks_send(): void
    {
        [$user, $business] = $this->setupTenant(utilityQuota: 0, walletBalance: 15.00);
        $customer = $this->customerFor($business);

        // Needs 30.30 but only 15.00 available
        $res = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'body' => 'Should fail',
        ])->assertUnprocessable();

        $this->assertStringContainsString('wallet balance (₦15.00) is insufficient', $res->json('errors.wallet.0'));
        $this->assertSame(0, Message::withoutGlobalScopes()->where('business_id', $business->id)->count());
    }

    public function test_expired_or_grace_subscription_allows_sending_with_wallet(): void
    {
        [$user, $business] = $this->setupTenant(utilityQuota: 0, walletBalance: 100.00);
        $customer = $this->customerFor($business);

        // Expire any subscription
        $business->subscriptions()->update(['status' => 'expired']);

        $res = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'body' => 'Pay as you go message',
        ])->assertCreated();

        $msg = Message::withoutGlobalScopes()->findOrFail($res->json('data.id'));
        $this->assertSame('wallet', $msg->billing_source);
        $this->assertSame(30.30, (float) $msg->unit_price);

        $wallet = Wallet::withoutGlobalScopes()->where('business_id', $business->id)->first();
        $this->assertEquals(69.70, (float) $wallet->balance);
    }

    public function test_failed_delivery_webhook_automatically_refunds_wallet(): void
    {
        [$user, $business] = $this->setupTenant(utilityQuota: 0, walletBalance: 100.00);
        $customer = $this->customerFor($business);

        $res = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'body' => 'Hello',
        ])->assertCreated();

        $msg = Message::withoutGlobalScopes()->findOrFail($res->json('data.id'));
        $wallet = Wallet::withoutGlobalScopes()->where('business_id', $business->id)->first();
        $this->assertEquals(69.70, (float) $wallet->balance);

        // Webhook reports delivery failed
        $this->webhookStatus($business, $msg, 'failed');

        $msg->refresh();
        $this->assertSame('failed', $msg->status);
        $this->assertNotNull($msg->refunded_at);

        // Wallet was refunded back to 100.00
        $wallet->refresh();
        $this->assertEquals(100.00, (float) $wallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 30.30,
            'balance_after' => 100.00,
            'reference' => "refund_msg_{$msg->id}",
        ]);
    }

    public function test_local_send_job_failure_automatically_refunds_wallet(): void
    {
        [$user, $business] = $this->setupTenant(utilityQuota: 0, walletBalance: 100.00);
        $customer = $this->customerFor($business);

        $res = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'body' => 'Local fail test',
        ])->assertCreated();

        $msg = Message::withoutGlobalScopes()->findOrFail($res->json('data.id'));
        $wallet = Wallet::withoutGlobalScopes()->where('business_id', $business->id)->first();
        $this->assertEquals(69.70, (float) $wallet->balance);

        // Simulate SendWhatsAppMessage terminal failure (e.g., customer has no WA number)
        $customer->update(['phone' => null, 'phone_normalized' => null]);

        $job = new SendWhatsAppMessage($msg->id);
        $job->handle(
            app(\App\Services\WhatsAppCloudApi::class),
            app(\App\Services\NotificationService::class)
        );

        $msg->refresh();
        $this->assertSame('failed', $msg->status);
        $this->assertNotNull($msg->refunded_at);

        $wallet->refresh();
        $this->assertEquals(100.00, (float) $wallet->balance);
    }

    public function test_refund_is_strictly_idempotent_no_double_refund(): void
    {
        [$user, $business] = $this->setupTenant(utilityQuota: 0, walletBalance: 100.00);
        $customer = $this->customerFor($business);

        $res = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'body' => 'Idempotent refund test',
        ])->assertCreated();

        $msg = Message::withoutGlobalScopes()->findOrFail($res->json('data.id'));

        // First failure
        $this->webhookStatus($business, $msg, 'failed');
        $wallet = Wallet::withoutGlobalScopes()->where('business_id', $business->id)->first();
        $this->assertEquals(100.00, (float) $wallet->balance);

        // Repeat webhook failure event
        $this->webhookStatus($business, $msg->fresh(), 'failed');

        // Balance must still be 100.00, not 130.30
        $wallet->refresh();
        $this->assertEquals(100.00, (float) $wallet->balance);

        $this->assertSame(
            1,
            WalletTransaction::withoutGlobalScopes()
                ->where('reference', "refund_msg_{$msg->id}")
                ->count()
        );
    }

    public function test_tenant_can_submit_topup_request_and_min_validation(): void
    {
        [$user, $business] = $this->setupTenant(utilityQuota: 0, walletBalance: 0);

        $file = UploadedFile::fake()->create('receipt.png', 500, 'image/png');

        // 1. Amount below minimum (₦10,000) fails
        $this->actingAs($user)->postJson('/api/v1/wallet/topups', [
            'amount' => 5000,
            'receipt' => $file,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        // 2. Valid amount succeeds
        $res = $this->actingAs($user)->postJson('/api/v1/wallet/topups', [
            'amount' => 15000,
            'receipt' => $file,
            'reference' => 'TXN-98765',
            'note' => 'Paid via GTBank transfer',
        ])->assertCreated();

        $this->assertDatabaseHas('topup_requests', [
            'business_id' => $business->id,
            'amount' => 15000.00,
            'reference' => 'TXN-98765',
            'status' => 'pending',
        ]);

        $topupId = $res->json('data.id');

        // Tenant can view their topup receipt
        $this->actingAs($user)->getJson("/api/v1/wallet/topups/{$topupId}/receipt")->assertOk();
    }

    public function test_admin_can_approve_topup_and_credits_wallet_idempotently(): void
    {
        [$user, $business] = $this->setupTenant(utilityQuota: 0, walletBalance: 0);
        $admin = $this->superAdmin();

        $file = UploadedFile::fake()->create('receipt.pdf', 300, 'application/pdf');
        $topup = app(WalletService::class)->submitTopup($business, $user, $file, 20000.00, 'REF-123');

        // Admin approves
        $res = $this->actingAs($admin)->postJson("/api/v1/admin/topup-requests/{$topup->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $res->json('data.topup_request.status'));

        $wallet = Wallet::withoutGlobalScopes()->where('business_id', $business->id)->first();
        $this->assertEquals(20000.00, (float) $wallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 20000.00,
            'reference' => "topup_{$topup->id}",
        ]);

        // Attempting to approve again fails idempotently
        $this->actingAs($admin)->postJson("/api/v1/admin/topup-requests/{$topup->id}/approve")
            ->assertUnprocessable();

        // Wallet balance remains 20,000 (not 40,000)
        $wallet->refresh();
        $this->assertEquals(20000.00, (float) $wallet->balance);
    }

    public function test_admin_can_reject_topup_with_mandatory_reason(): void
    {
        [$user, $business] = $this->setupTenant(utilityQuota: 0, walletBalance: 0);
        $admin = $this->superAdmin();

        $file = UploadedFile::fake()->create('receipt.png', 200, 'image/png');
        $topup = app(WalletService::class)->submitTopup($business, $user, $file, 10000.00);

        // Rejection without reason fails
        $this->actingAs($admin)->postJson("/api/v1/admin/topup-requests/{$topup->id}/reject", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        // Rejection with reason succeeds
        $this->actingAs($admin)->postJson("/api/v1/admin/topup-requests/{$topup->id}/reject", [
            'reason' => 'Receipt does not show payment to Crediping account.',
        ])->assertOk();

        $topup->refresh();
        $this->assertSame('rejected', $topup->status);
        $this->assertSame('Receipt does not show payment to Crediping account.', $topup->rejection_reason);

        // Wallet was NOT credited
        $wallet = Wallet::withoutGlobalScopes()->where('business_id', $business->id)->first();
        $this->assertEquals(0.00, (float) ($wallet?->balance ?? 0));
    }

    public function test_low_balance_alert_triggers_once_on_crossing_and_resets_on_topup(): void
    {
        // Threshold is 200. Start with balance 220
        [$user, $business] = $this->setupTenant(utilityQuota: 0, walletBalance: 220.00);
        $admin = $this->superAdmin();
        $customer = $this->customerFor($business);

        $wallet = Wallet::withoutGlobalScopes()->where('business_id', $business->id)->first();
        $this->assertFalse((bool) $wallet->low_balance_alerted);

        // Send message: cost 30.30 -> balance becomes 189.70 (< 200)
        $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'body' => 'Crossing threshold message',
        ])->assertCreated();

        $wallet->refresh();
        $this->assertEquals(189.70, (float) $wallet->balance);
        $this->assertTrue((bool) $wallet->low_balance_alerted);

        $this->assertDatabaseHas('notifications', [
            'business_id' => $business->id,
            'type' => 'wallet_low_balance',
        ]);

        $firstNotificationCount = Notification::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('type', 'wallet_low_balance')
            ->count();
        $this->assertSame(1, $firstNotificationCount);

        // Send second message: cost 30.30 -> balance becomes 159.40 (< 200)
        $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'body' => 'Second below threshold message',
        ])->assertCreated();

        // Alert must NOT be triggered again
        $this->assertSame(
            1,
            Notification::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('type', 'wallet_low_balance')
                ->count()
        );

        // Admin approves a top-up of 10,000 -> balance becomes 10,159.40 (>= 200)
        $file = UploadedFile::fake()->create('receipt.png', 200, 'image/png');
        $topup = app(WalletService::class)->submitTopup($business, $user, $file, 10000.00);
        $this->actingAs($admin)->postJson("/api/v1/admin/topup-requests/{$topup->id}/approve")->assertOk();

        $wallet->refresh();
        $this->assertFalse((bool) $wallet->low_balance_alerted);
    }

    public function test_admin_payment_settings_update_and_pricing_calculation(): void
    {
        $admin = $this->superAdmin();

        // Check initial show endpoint
        $res = $this->actingAs($admin)->getJson('/api/v1/admin/payment-settings')->assertOk();
        $this->assertSame(30.30, (float) $res->json('data.computed_pricing.utility_price_per_message'));

        // Update settings: fx 1600, markup 50%, vat 7.5%
        // base_cost = 0.0101 * 1600 * (1 + 0.075) = 17.372
        // price = 17.372 * (1 + 0.50) = 26.058 -> 26.06
        $updateRes = $this->actingAs($admin)->putJson('/api/v1/admin/payment-settings', [
            'usd_ngn_rate' => 1600,
            'markup_percent' => 50,
            'vat_percent' => 7.5,
            'min_topup_amount' => 15000,
            'low_balance_threshold' => 300,
        ])->assertOk();

        $this->assertSame(26.06, (float) $updateRes->json('data.computed_pricing.utility_price_per_message'));
        $this->assertSame(15000.00, (float) $updateRes->json('data.settings.min_topup_amount'));
        $this->assertSame(300.00, (float) $updateRes->json('data.settings.low_balance_threshold'));
    }
}
