<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $phoneId = data_get($this->payload, 'entry.0.changes.0.value.metadata.phone_number_id');
        $value = data_get($this->payload, 'entry.0.changes.0.value', []);
        $connection = WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', $phoneId)->first();
        if (!$connection) return;
        $context = app(TenantContext::class);
        $context->set($connection->business_id);
        try {
            foreach (data_get($value, 'messages', []) as $incoming) {
                $from = data_get($incoming, 'from');
                $customer = Customer::query()->where('phone', $from)->first();
                if (!$customer) continue;
                $conversation = Conversation::query()->firstOrCreate(
                    ['customer_id' => $customer->id, 'whatsapp_connection_id' => $connection->id],
                    ['status' => 'open'],
                );
                Message::query()->firstOrCreate(
                    ['whatsapp_message_id' => data_get($incoming, 'id')],
                    ['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'direction' => 'inbound', 'type' => data_get($incoming, 'type', 'text'), 'body' => data_get($incoming, 'text.body'), 'status' => 'delivered', 'sent_at' => now()],
                );
                $conversation->update(['last_message_at' => now()]);
            }
            foreach (data_get($value, 'statuses', []) as $status) {
                $message = Message::query()->where('whatsapp_message_id', data_get($status, 'id'))->first();
                if ($message) {
                    $state = data_get($status, 'status');
                    $updates = ['status' => $state];
                    if (in_array($state, ['sent', 'delivered', 'read'], true)) $updates[$state . '_at'] = now();
                    if ($state === 'failed') $updates['error_message'] = data_get($status, 'errors.0.title');
                    $message->update($updates);
                }
            }
        } finally { $context->clear(); }
    }
}
