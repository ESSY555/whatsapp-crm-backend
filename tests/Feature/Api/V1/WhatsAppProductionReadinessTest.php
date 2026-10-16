<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SendBroadcastMessages;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\ReminderLog;
use App\Models\ScheduledMessage;
use App\Models\WhatsAppConnection;
use App\Services\BroadcastService;
use App\Services\CustomerImportService;
use App\Services\NotificationService;
use App\Services\QuotaService;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppCloudApi;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WhatsAppProductionReadinessTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    public function test_nigerian_and_international_phone_number_normalization(): void
    {
        // 11 digits starting with 0 (Nigerian local formats)
        $this->assertSame('2348031234567', Customer::normalizePhone('08031234567'));
        $this->assertSame('2347012345678', Customer::normalizePhone('07012345678'));
        $this->assertSame('2349012345678', Customer::normalizePhone('09012345678'));
        $this->assertSame('2348112345678', Customer::normalizePhone('08112345678'));
        $this->assertSame('2349112345678', Customer::normalizePhone('09112345678'));

        // 10 digits without leading zero
        $this->assertSame('2348031234567', Customer::normalizePhone('8031234567'));
        $this->assertSame('2347031234567', Customer::normalizePhone('7031234567'));

        // Nigerian format with +234
        $this->assertSame('2348031234567', Customer::normalizePhone('+2348031234567'));
        $this->assertSame('2348031234567', Customer::normalizePhone('+234 803 123 4567'));

        // Nigerian format with 234 and accidental zero (23408031234567)
        $this->assertSame('2348031234567', Customer::normalizePhone('23408031234567'));
        $this->assertSame('2348031234567', Customer::normalizePhone('+23408031234567'));

        // Standard 13-digit Nigerian international
        $this->assertSame('2348031234567', Customer::normalizePhone('2348031234567'));

        // International numbers
        $this->assertSame('14155552671', Customer::normalizePhone('+1 (415) 555-2671'));
        $this->assertSame('447911123456', Customer::normalizePhone('+44 7911 123456'));
        $this->assertSame('447911123456', Customer::normalizePhone('00447911123456'));

        // Null and empty
        $this->assertNull(Customer::normalizePhone(null));
        $this->assertNull(Customer::normalizePhone(''));
        $this->assertNull(Customer::normalizePhone('   '));
    }

    public function test_inbound_webhook_matches_existing_customer_with_local_phone_format(): void
    {
        [$user, $business] = $this->membership();
        $connection = $this->connectionFor($business);

        // Customer created with local Nigerian 11-digit format
        $customer = $this->customerFor($business, [
            'name' => 'Tunde Bakare',
            'phone' => '08031234567',
        ]);

        $this->assertSame('2348031234567', $customer->phone_normalized);

        // Webhook arrives from Meta with canonical international format
        $incoming = [
            'from' => '2348031234567',
            'id' => 'wamid.inbound.1001',
            'type' => 'text',
            'text' => ['body' => 'Hello there!'],
        ];

        $service = app(WhatsAppService::class);
        $message = $this->asTenant($business->id, fn () => $service->handleIncoming($connection, $incoming));

        $this->assertNotNull($message);
        $this->assertSame($customer->id, $message->customer_id);
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('Hello there!', $message->body);

        // Ensure no duplicate walk-in customer was created
        $this->assertSame(1, Customer::where('business_id', $business->id)->count());
    }

    public function test_customer_import_normalizes_nigerian_phones(): void
    {
        [$user, $business] = $this->membership();

        $csv = implode("\n", [
            'name,phone,email',
            'Adebayo,08031234567,ade@example.com',
            'Chioma,+2348099887766,chi@example.com',
            'Emeka,8022334455,emeka@example.com',
        ]);

        $service = app(CustomerImportService::class);
        $result = $this->asTenant($business->id, fn () => $service->import($csv));

        $this->assertSame(3, $result['imported']);

        $ade = Customer::where('business_id', $business->id)->where('name', 'Adebayo')->first();
        $this->assertSame('2348031234567', $ade->phone_normalized);

        $chi = Customer::where('business_id', $business->id)->where('name', 'Chioma')->first();
        $this->assertSame('2348099887766', $chi->phone_normalized);

        $emeka = Customer::where('business_id', $business->id)->where('name', 'Emeka')->first();
        $this->assertSame('2348022334455', $emeka->phone_normalized);
    }

    public function test_status_reconciliation_recovers_from_dispatch_timeout_to_delivered_and_read(): void
    {
        [$user, $business] = $this->membership();
        $connection = $this->connectionFor($business);
        $customer = $this->customerFor($business);

        $conversation = $this->asTenant($business->id, fn () => Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_connection_id' => $connection->id,
            'status' => 'open',
        ]));

        $message = $this->asTenant($business->id, fn () => Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Time sensitive alert',
            'status' => 'failed',
            'whatsapp_message_id' => 'wamid.timeout.reconcile.1',
            'error_code' => 'DISPATCH_TIMEOUT',
            'error_message' => 'cURL error 28: Operation timed out',
            'failed_at' => now()->subMinutes(2),
        ]));

        $service = app(WhatsAppService::class);

        // Webhook arrives indicating message was delivered by Meta
        $this->asTenant($business->id, fn () => $service->applyStatus('wamid.timeout.reconcile.1', 'delivered'));

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertNull($message->error_message);

        // Subsequent read status updates to read
        $this->asTenant($business->id, fn () => $service->applyStatus('wamid.timeout.reconcile.1', 'read'));

        $message->refresh();
        $this->assertSame('read', $message->status);
        $this->assertNotNull($message->read_at);

        // Regressive delivered status webhook must NOT downgrade read status
        $this->asTenant($business->id, fn () => $service->applyStatus('wamid.timeout.reconcile.1', 'delivered'));

        $message->refresh();
        $this->assertSame('read', $message->status);
    }

    public function test_status_reconciliation_records_meta_terminal_failure(): void
    {
        [$user, $business] = $this->membership();
        $connection = $this->connectionFor($business);
        $customer = $this->customerFor($business);

        $conversation = $this->asTenant($business->id, fn () => Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_connection_id' => $connection->id,
            'status' => 'open',
        ]));

        $message = $this->asTenant($business->id, fn () => Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Important notification',
            'status' => 'sent',
            'whatsapp_message_id' => 'wamid.terminal.fail.1',
            'sent_at' => now(),
        ]));

        $service = app(WhatsAppService::class);

        $this->asTenant($business->id, fn () => $service->applyStatus('wamid.terminal.fail.1', 'failed', 'Message undeliverable'));

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('Message undeliverable', $message->error_message);
    }

    public function test_atomic_quota_metering_prevents_duplicate_billing_on_concurrent_webhooks(): void
    {
        [$user, $business] = $this->membership();
        $connection = $this->connectionFor($business);
        $customer = $this->customerFor($business);

        $conversation = $this->asTenant($business->id, fn () => Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_connection_id' => $connection->id,
            'status' => 'open',
        ]));

        $message = $this->asTenant($business->id, fn () => Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Transactional receipt',
            'status' => 'sent',
            'category' => 'utility',
            'charged_at' => null,
        ]));

        $quota = app(QuotaService::class);

        // Record delivered multiple times concurrently
        $recordedFirst = $this->asTenant($business->id, fn () => $quota->recordDelivered($message));
        $recordedSecond = $this->asTenant($business->id, fn () => $quota->recordDelivered($message));
        $recordedThird = $this->asTenant($business->id, fn () => $quota->recordDelivered($message));

        $this->assertTrue($recordedFirst);
        $this->assertFalse($recordedSecond);
        $this->assertFalse($recordedThird);

        $message->refresh();
        $this->assertNotNull($message->charged_at);
    }

    public function test_queue_separation_and_job_queue_assignment(): void
    {
        $sendJob = new SendWhatsAppMessage(1);
        $this->assertSame('whatsapp', $sendJob->queue);

        $webhookJob = new ProcessWhatsAppWebhook([]);
        $this->assertSame('whatsapp', $webhookJob->queue);

        $broadcastJob = new SendBroadcastMessages(1);
        $this->assertSame('whatsapp_bulk', $broadcastJob->queue);

        // retry_after must safely exceed job timeouts
        $retryAfter = config('queue.connections.database.retry_after');
        $this->assertGreaterThanOrEqual(300, $retryAfter);
        $this->assertLessThan($retryAfter, $broadcastJob->timeout);
        $this->assertLessThan($retryAfter, $sendJob->timeout);
    }

    public function test_send_whatsapp_message_transient_error_releases_and_reverts_status_to_queued(): void
    {
        [$user, $business] = $this->membership();
        $connection = $this->connectionFor($business);
        $customer = $this->customerFor($business);

        $conversation = $this->asTenant($business->id, fn () => Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_connection_id' => $connection->id,
            'status' => 'open',
        ]));

        $message = $this->asTenant($business->id, fn () => Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Retry me please',
            'status' => 'queued',
        ]));

        // Mock 429 rate limit
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Rate limit hit. (#80007)',
                    'code' => 80007,
                ],
            ], 429),
        ]);

        $job = new SendWhatsAppMessage($message->id);
        $job->handle(app(WhatsAppCloudApi::class), app(NotificationService::class));

        // Message should be reset to queued for retry rather than marked failed
        $message->refresh();
        $this->assertSame('queued', $message->status);
    }

    public function test_send_whatsapp_message_terminal_error_marks_failed(): void
    {
        [$user, $business] = $this->membership();
        $connection = $this->connectionFor($business);
        $customer = $this->customerFor($business);

        $conversation = $this->asTenant($business->id, fn () => Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_connection_id' => $connection->id,
            'status' => 'open',
        ]));

        $message = $this->asTenant($business->id, fn () => Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Terminal fail',
            'status' => 'queued',
        ]));

        // Mock 400 invalid template parameter
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Invalid template parameter: count mismatch',
                    'code' => 100,
                ],
            ], 400),
        ]);

        $job = new SendWhatsAppMessage($message->id);

        try {
            $job->handle(app(WhatsAppCloudApi::class), app(NotificationService::class));
        } catch (\Throwable $e) {
            // Expected terminal throw
        }

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('Invalid template parameter: count mismatch', $message->error_message);
    }

    public function test_broadcast_counters_recalculated_accurately(): void
    {
        [$user, $business] = $this->membership();
        $customer1 = $this->customerFor($business);
        $customer2 = $this->customerFor($business);
        $customer3 = $this->customerFor($business);

        $broadcast = $this->asTenant($business->id, fn () => Broadcast::create([
            'created_by' => $user->id,
            'name' => 'Flash Sale',
            'status' => 'processing',
            'total_recipients' => 3,
            'sent_count' => 0,
            'delivered_count' => 0,
            'failed_count' => 0,
        ]));

        $rec1 = $this->asTenant($business->id, fn () => BroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'customer_id' => $customer1->id,
            'phone' => '2348030000001',
            'status' => 'delivered',
        ]));

        $rec2 = $this->asTenant($business->id, fn () => BroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'customer_id' => $customer2->id,
            'phone' => '2348030000002',
            'status' => 'failed',
            'error_message' => 'Network failure',
        ]));

        $rec3 = $this->asTenant($business->id, fn () => BroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'customer_id' => $customer3->id,
            'phone' => '2348030000003',
            'status' => 'sent',
        ]));

        $service = app(BroadcastService::class);
        $service->complete($broadcast);

        $broadcast->refresh();
        $this->assertSame('completed', $broadcast->status);
        $this->assertSame(2, $broadcast->sent_count); // rec1 + rec3
        $this->assertSame(1, $broadcast->delivered_count); // rec1
        $this->assertSame(1, $broadcast->failed_count); // rec2
    }

    public function test_scheduled_message_lifecycle_updated_on_terminal_failure(): void
    {
        [$user, $business] = $this->membership();
        $connection = $this->connectionFor($business);
        $customer = $this->customerFor($business);

        $conversation = $this->asTenant($business->id, fn () => Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_connection_id' => $connection->id,
            'status' => 'open',
        ]));

        $scheduled = $this->asTenant($business->id, fn () => ScheduledMessage::create([
            'customer_id' => $customer->id,
            'scheduled_at' => now()->subMinute(),
            'content' => 'Happy birthday!',
            'status' => 'processing',
        ]));

        $message = $this->asTenant($business->id, fn () => Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Happy birthday!',
            'status' => 'queued',
            'metadata' => ['scheduled_message_id' => $scheduled->id],
        ]));

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Permanent error', 'code' => 100],
            ], 400),
        ]);

        $job = new SendWhatsAppMessage($message->id);

        try {
            $job->handle(app(WhatsAppCloudApi::class), app(NotificationService::class));
        } catch (\Throwable $e) {
            // Expected
        }

        $scheduled->refresh();
        $this->assertSame('failed', $scheduled->status);
        $this->assertStringContainsString('Permanent error', (string) $scheduled->error_message);
    }

    public function test_webhook_controller_returns_200_and_enforces_hmac(): void
    {
        Config::set('services.whatsapp.app_secret', 'production-secret-key-12345');
        Queue::fake();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-123',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => 'phone-123'],
                        'statuses' => [],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $rawBody = json_encode($payload);
        $hmac = hash_hmac('sha256', $rawBody, 'production-secret-key-12345');

        // Without signature: rejected 401
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $rawBody)->assertUnauthorized();

        // With signature: accepted 200 OK
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . $hmac,
        ], $rawBody)->assertOk()->assertJson(['success' => true]);
    }
}
