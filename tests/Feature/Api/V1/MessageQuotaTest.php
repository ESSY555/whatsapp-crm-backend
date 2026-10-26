<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Business;
use App\Models\Message;
use App\Models\MessageUsage;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingService;
use App\Services\QuotaService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Quota enforcement: delivery-based metering, category isolation and
 * per-cycle reset, per the billing spec.
 */
class MessageQuotaTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    private function subscribedMembership(int $utilityQuota, int $days = 30): array
    {
        Queue::fake([SendWhatsAppMessage::class]);
        $user = User::factory()->create();
        $business = Business::create(['name' => 'Metered Co', 'slug' => 'metered-' . uniqid(), 'currency' => 'NGN', 'status' => 'active']);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);
        $plan = $this->plan('starter', ['price' => 15000, 'utility_quota' => $utilityQuota, 'marketing_quota' => 0, 'allows_broadcasts' => false, 'is_trial_default' => true, 'sort_order' => 1]);
        $subscription = $this->subscribe($business, $plan, $days);
        $this->connectionFor($business);

        return [$user, $business, $subscription];
    }

    /** Simulate Meta's status webhook for a message. */
    private function webhookStatus(Business $business, Message $message, string $status): void
    {
        $message->update(['whatsapp_message_id' => $message->whatsapp_message_id ?? "wamid.{$message->id}", 'status' => 'sent']);
        $this->asTenant($business->id, fn () => app(WhatsAppService::class)->applyStatus($message->whatsapp_message_id, $status));
    }

    public function test_usage_is_charged_on_delivery_not_on_send(): void
    {
        [$user, $business] = $this->subscribedMembership(10);
        $customer = $this->customerFor($business);

        $id = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Invoice ready'])->assertCreated()->json('data.id');
        $message = Message::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('utility', $message->billing_category);

        // Queued/sent: nothing charged yet, but the message is reserved against the quota.
        $this->assertSame(0, MessageUsage::withoutGlobalScopes()->sum('sent_count'));
        $summary = $this->actingAs($user)->getJson('/api/v1/billing')->json('data.usage.utility');
        $this->assertSame(0, $summary['sent']);
        $this->assertSame(1, $summary['in_flight']);
        $this->assertSame(9, $summary['remaining']);

        $this->webhookStatus($business, $message, 'delivered');
        $this->assertNotNull($message->fresh()->charged_at);
        $this->assertSame(1, (int) MessageUsage::withoutGlobalScopes()->where('category', 'utility')->sum('sent_count'));

        // A later "read" for the same message does not double count.
        $this->webhookStatus($business, $message->fresh(), 'read');
        $this->assertSame(1, (int) MessageUsage::withoutGlobalScopes()->where('category', 'utility')->sum('sent_count'));

        $summary = $this->actingAs($user)->getJson('/api/v1/billing')->json('data.usage.utility');
        $this->assertSame(['sent' => 1, 'in_flight' => 0, 'remaining' => 9], ['sent' => $summary['sent'], 'in_flight' => $summary['in_flight'], 'remaining' => $summary['remaining']]);
    }

    public function test_failed_messages_do_not_consume_quota(): void
    {
        [$user, $business] = $this->subscribedMembership(2);
        $customer = $this->customerFor($business);

        $id = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Hi'])->assertCreated()->json('data.id');
        $this->webhookStatus($business, Message::withoutGlobalScopes()->findOrFail($id), 'failed');

        $summary = $this->actingAs($user)->getJson('/api/v1/billing')->json('data.usage.utility');
        $this->assertSame(0, $summary['sent']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(2, $summary['remaining']);
    }

    public function test_quota_exhaustion_hard_blocks_further_utility_sends(): void
    {
        [$user, $business] = $this->subscribedMembership(2);
        $customer = $this->customerFor($business);

        foreach ([1, 2] as $i) {
            $id = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => "Msg {$i}"])->assertCreated()->json('data.id');
            $this->webhookStatus($business, Message::withoutGlobalScopes()->findOrFail($id), 'delivered');
        }

        $res = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'One too many'])->assertUnprocessable();
        $this->assertStringContainsString('WhatsApp quota reached for this cycle', $res->json('errors.plan.0'));
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'quota_exhausted']);
        $this->assertSame(2, Message::withoutGlobalScopes()->where('business_id', $business->id)->count());

        // In-flight messages count too: with quota 2 and two queued (undelivered), a third is refused.
        [$user2, $business2] = $this->subscribedMembership(2);
        $customer2 = $this->customerFor($business2);
        $this->actingAs($user2)->postJson('/api/v1/messages', ['customer_id' => $customer2->id, 'body' => 'A'])->assertCreated();
        $this->actingAs($user2)->postJson('/api/v1/messages', ['customer_id' => $customer2->id, 'body' => 'B'])->assertCreated();
        $this->actingAs($user2)->postJson('/api/v1/messages', ['customer_id' => $customer2->id, 'body' => 'C'])->assertUnprocessable()->assertJsonValidationErrors('plan');
    }

    public function test_overage_is_allowed_when_hard_block_is_disabled(): void
    {
        config(['billing.hard_block' => false]);
        [$user, $business] = $this->subscribedMembership(1);
        $customer = $this->customerFor($business);

        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'A'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'B'])->assertCreated();
        // Flagged for review instead of blocked.
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'quota_exhausted']);
    }

    public function test_marketing_is_isolated_from_the_utility_quota(): void
    {
        [$user, $business] = $this->subscribedMembership(5);
        $customer = $this->customerFor($business);

        // Broadcasts are not part of any v1 plan.
        $res = $this->actingAs($user)->postJson('/api/v1/broadcasts', ['name' => 'Promo', 'content' => 'Sale!', 'customer_ids' => [$customer->id]])->assertUnprocessable();
        $this->assertStringContainsString('not included in your Starter plan', $res->json('errors.plan.0'));

        // A marketing-category template is also refused, and the utility quota is untouched.
        $promo = $this->templateFor($business, ['category' => 'marketing', 'content' => 'Big sale {{customer_name}}!']);
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'template_id' => $promo->id])->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->assertSame(5, $this->actingAs($user)->getJson('/api/v1/billing')->json('data.usage.utility.remaining'));

        // When a plan does include marketing, its deliveries land in the marketing bucket only.
        $business->subscriptions()->update(['status' => 'expired']);
        $this->subscribe($business, $this->plan('business', ['utility_quota' => 5, 'marketing_quota' => 3, 'allows_broadcasts' => true]));
        $id = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'template_id' => $promo->id])->assertCreated()->json('data.id');
        $message = Message::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('marketing', $message->billing_category);
        $this->webhookStatus($business, $message, 'delivered');

        $usage = $this->actingAs($user)->getJson('/api/v1/billing')->json('data.usage');
        $this->assertSame(1, $usage['marketing']['sent']);
        $this->assertSame(0, $usage['utility']['sent']);
        $this->assertSame(5, $usage['utility']['remaining']);
    }

    public function test_authentication_templates_are_tracked_separately_but_share_the_included_messages(): void
    {
        [$user, $business] = $this->subscribedMembership(2);
        $customer = $this->customerFor($business);
        $otp = $this->templateFor($business, ['category' => 'authentication', 'content' => 'Your code is {{code}}']);

        $id = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'template_id' => $otp->id, 'template_variables' => ['code' => '1234']])->assertCreated()->json('data.id');
        $message = Message::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('authentication', $message->billing_category);
        $this->webhookStatus($business, $message, 'delivered');

        $usage = $this->actingAs($user)->getJson('/api/v1/billing')->json('data.usage');
        $this->assertSame(1, collect($usage['by_category'])->firstWhere('category', 'authentication')['delivered']);
        $this->assertSame(1, $usage['utility']['remaining']);
    }

    public function test_service_window_replies_are_free(): void
    {
        [$user, $business] = $this->subscribedMembership(1);
        $connection = $business->whatsappConnections()->first();
        $customer = $this->customerFor($business);

        // Use the only included message.
        $id = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Invoice'])->assertCreated()->json('data.id');
        $this->webhookStatus($business, Message::withoutGlobalScopes()->findOrFail($id), 'delivered');
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Blocked'])->assertUnprocessable();

        // A customer message opens the 24h window; the reply is a free service message.
        $this->asTenant($business->id, fn () => app(WhatsAppService::class)->handleIncoming($connection, ['from' => '2348030000001', 'id' => 'wamid.in', 'type' => 'text', 'text' => ['body' => 'Hello?']]));
        $id = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Reply'])->assertCreated()->json('data.id');
        $this->assertSame('service', Message::withoutGlobalScopes()->findOrFail($id)->billing_category);
    }

    public function test_quota_resets_when_a_new_period_is_approved(): void
    {
        [$user, $business, $subscription] = $this->subscribedMembership(1, 30);
        $customer = $this->customerFor($business);

        $id = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Invoice'])->assertCreated()->json('data.id');
        $this->webhookStatus($business, Message::withoutGlobalScopes()->findOrFail($id), 'delivered');
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Blocked'])->assertUnprocessable();

        // The period ends and a new receipt is approved: fresh cycle, fresh quota.
        $subscription->update(['starts_at' => now()->subDays(31), 'ends_at' => now()->subDay()]);
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame('grace', $subscription->fresh()->status);

        app(BillingService::class)->grant($business, $subscription->plan, null, null, null, 'renewal');
        $this->assertSame('expired', $subscription->fresh()->status);

        $summary = $this->actingAs($user)->getJson('/api/v1/billing')->json('data');
        $this->assertSame('active', $summary['state']);
        $this->assertSame(0, $summary['usage']['utility']['sent']);
        $this->assertSame(1, $summary['usage']['utility']['remaining']);
        $this->assertSame(30, $summary['cycle']['days_remaining']);
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'New cycle'])->assertCreated();

        // The old cycle's usage row is kept as history, not merged into the new cycle.
        $rows = MessageUsage::withoutGlobalScopes()->where('business_id', $business->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows->first()->sent_count);
        $this->assertSame($subscription->id, $rows->first()->subscription_id);
    }

    public function test_lifecycle_active_grace_expired_and_access_rules(): void
    {
        config(['billing.grace_days' => 3]);
        [$user, $business, $subscription] = $this->subscribedMembership(5, 30);
        $customer = $this->customerFor($business);

        // Period ends -> grace: full access, reminder notification.
        $subscription->update(['starts_at' => now()->subDays(31), 'ends_at' => now()->subMinute()]);
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $subscription->refresh();
        $this->assertSame('grace', $subscription->status);
        $this->assertTrue($subscription->grace_ends_at->between(now()->addDays(2), now()->addDays(4)));
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'subscription_expiring']);
        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.state', 'grace')->assertJsonPath('data.subscription.status', 'grace');
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Still works'])->assertCreated();

        // Grace runs out -> expired: sending and broadcasts blocked, data still readable.
        $subscription->update(['grace_ends_at' => now()->subMinute()]);
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame('expired', $business->fresh()->status);
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'subscription_expired']);

        $this->actingAs($user)->getJson('/api/v1/billing')->assertJsonPath('data.state', 'expired')->assertJsonPath('data.plan', null);
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Blocked'])->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->actingAs($user)->postJson('/api/v1/broadcasts', ['name' => 'Promo', 'content' => 'Sale!', 'customer_ids' => [$customer->id]])->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->actingAs($user)->getJson('/api/v1/customers')->assertOk()->assertJsonPath('data.total', 1);
        $this->actingAs($user)->getJson("/api/v1/customers/{$customer->id}")->assertOk();
        $this->actingAs($user)->getJson('/api/v1/conversations')->assertOk();
    }

    public function test_stacked_renewal_expires_the_old_period_without_grace(): void
    {
        [$user, $business, $subscription] = $this->subscribedMembership(5, 30);
        $renewal = $this->asTenant($business->id, fn () => Subscription::create(['plan_id' => $subscription->plan_id, 'status' => 'active', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(29)]));
        $subscription->update(['starts_at' => now()->subDays(31), 'ends_at' => now()->subMinute()]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame('active', $renewal->fresh()->status);
        $this->assertSame('active', $business->fresh()->status);
        $this->assertDatabaseMissing('notifications', ['business_id' => $business->id, 'type' => 'subscription_expiring']);
        $this->assertSame(0, app(QuotaService::class)->inFlightCount($business, MessageUsage::periodStartFor($business, $renewal), ['utility']));
    }

    public function test_all_subscription_packages_allow_bulk_broadcasts_sharing_included_messages(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        $user = User::factory()->create();
        $business = Business::create(['name' => 'Bulk Co', 'slug' => 'bulk-' . uniqid(), 'currency' => 'NGN', 'status' => 'active']);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);
        $this->connectionFor($business);

        $customer1 = $this->customerFor($business, ['phone' => '+2348030000001']);
        $customer2 = $this->customerFor($business, ['phone' => '+2348030000002']);

        // 1. Starter plan: allows broadcasts and has 10 messages included
        $starterPlan = $this->plan('starter', [
            'name' => 'Starter',
            'price' => 15000,
            'utility_quota' => 10,
            'marketing_quota' => 0,
            'allows_broadcasts' => true,
        ]);
        $this->subscribe($business, $starterPlan, 30);

        // Can create and start a broadcast with 2 recipients
        $res = $this->actingAs($user)->postJson('/api/v1/broadcasts', [
            'name' => 'Sale Announcement',
            'content' => 'Special discount for our customers!',
            'customer_ids' => [$customer1->id, $customer2->id],
            'send_now' => true,
        ])->assertCreated();

        $broadcastId = $res->json('data.id');
        $this->assertDatabaseHas('broadcasts', ['id' => $broadcastId, 'total_recipients' => 2]);

        // Process the broadcast batch
        $broadcast = \App\Models\Broadcast::withoutGlobalScopes()->findOrFail($broadcastId);
        $this->asTenant($business->id, fn () => app(\App\Services\BroadcastService::class)->processBatch($broadcast));

        // 2 messages were sent
        $messages = Message::withoutGlobalScopes()->where('business_id', $business->id)->get();
        $this->assertCount(2, $messages);
        foreach ($messages as $msg) {
            $this->webhookStatus($business, $msg, 'delivered');
        }

        // Remaining included quota is decremented from 10 to 8
        $billing = $this->actingAs($user)->getJson('/api/v1/billing')->json('data');
        $this->assertSame(8, $billing['usage']['utility']['remaining']);
        $this->assertSame(2, $billing['usage']['utility']['sent']);

        // 2. Business package also allows broadcasts
        $business->subscriptions()->update(['status' => 'expired']);
        $bizPlan = $this->plan('business', [
            'name' => 'Business',
            'price' => 30000,
            'utility_quota' => 50,
            'marketing_quota' => 0,
            'allows_broadcasts' => true,
        ]);
        $this->subscribe($business, $bizPlan, 30);

        $this->actingAs($user)->postJson('/api/v1/broadcasts', [
            'name' => 'Business Broadcast',
            'content' => 'Hello from Business plan!',
            'customer_ids' => [$customer1->id],
            'send_now' => true,
        ])->assertCreated();

        // 3. Professional package also allows broadcasts
        $business->subscriptions()->update(['status' => 'expired']);
        $proPlan = $this->plan('professional', [
            'name' => 'Professional',
            'price' => 60000,
            'utility_quota' => 100,
            'marketing_quota' => 0,
            'allows_broadcasts' => true,
        ]);
        $this->subscribe($business, $proPlan, 30);

        $this->actingAs($user)->postJson('/api/v1/broadcasts', [
            'name' => 'Pro Broadcast',
            'content' => 'Hello from Pro plan!',
            'customer_ids' => [$customer1->id],
            'send_now' => true,
        ])->assertCreated();
    }
}
