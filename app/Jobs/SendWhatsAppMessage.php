<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\BroadcastService;
use App\Services\NotificationService;
use App\Services\QuotaService;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppCloudApi;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one queued outbound message through the WhatsApp Cloud API.
 * Safe to retry: a message that already left the "queued" state is skipped.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $messageId)
    {
    }

    public function handle(WhatsAppCloudApi $api, NotificationService $notifications): void
    {
        $message = Message::withoutGlobalScopes()->find($this->messageId);
        if (!$message) {
            return;
        }

        TenantManager::runAs($message->business_id, function () use ($message, $api, $notifications) {
            if ($message->status !== 'queued' || $message->direction !== 'outbound') {
                return;
            }

            $connection = $message->conversation?->connection;
            $to = $message->customer?->whatsappNumber();

            if (!$connection || !$connection->isActive()) {
                $this->fail($message, 'WhatsApp connection is not active.', $notifications);
                return;
            }
            if (!$to) {
                $this->fail($message, 'Customer has no valid WhatsApp phone number.', $notifications);
                return;
            }

            $message->update(['status' => 'sending']);

            try {
                $waId = $api->send($connection, $to, $message);
                $message->update([
                    'status' => 'sent',
                    'whatsapp_message_id' => $waId,
                    'sent_at' => now(),
                    'error_message' => null,
                ]);
                $connection->update(['last_verified_at' => now()]);
                // Quota is charged on the delivered webhook, not here.
            } catch (WhatsAppApiException $e) {
                if ($e->isCredentialError()) {
                    $connection->update(['status' => 'error']);
                    $notifications->connectionFailure($connection, $e->getMessage());
                }
                $this->fail($message, $e->getMessage(), $notifications);
            } catch (Throwable $e) {
                Log::error("WhatsApp send failed for message {$message->id}: {$e->getMessage()}");
                $this->fail($message, 'Network error while contacting WhatsApp.', $notifications);
            }
        });
    }

    private function fail(Message $message, string $reason, NotificationService $notifications): void
    {
        $message->update(['status' => 'failed', 'error_message' => $reason]);
        app(QuotaService::class)->recordFailed($message);
        $notifications->messageFailed($message);
        // Keep broadcast recipient rows and counters in step with the failure.
        app(BroadcastService::class)->syncRecipientStatus($message);
    }
}
