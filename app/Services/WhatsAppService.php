<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\WhatsAppConnection;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Outbound and inbound message handling for the current tenant. Sending is
 * always two-step: persist the message as "queued", then let a queue job talk
 * to the WhatsApp Cloud API.
 */
class WhatsAppService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /**
     * Create and queue an outbound message.
     *
     * Accepts either conversation_id or customer_id, and either body or
     * template_id (+ template_variables).
     */
    public function sendMessage(array $data): Message
    {
        return DB::transaction(function () use ($data) {
            $conversation = isset($data['conversation_id'])
                ? Conversation::query()->findOrFail($data['conversation_id'])
                : $this->conversationFor(Customer::query()->findOrFail($data['customer_id']));

            $template = null;
            $variables = $data['template_variables'] ?? [];

            if (!empty($data['template_id'])) {
                $template = MessageTemplate::query()->findOrFail($data['template_id']);
                if (!$template->isApproved()) {
                    throw ValidationException::withMessages(['template_id' => ["Template '{$template->name}' is not approved for sending."]]);
                }
                $variables = array_merge($this->defaultVariables($conversation->customer, $data['invoice'] ?? null), $variables);
                $body = $template->render($variables);
            } else {
                $body = trim((string) ($data['body'] ?? ''));
                if ($body === '') {
                    throw ValidationException::withMessages(['body' => ['A message body or template is required.']]);
                }
            }

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'outbound',
                'type' => $template?->whatsapp_template_id ? 'template' : 'text',
                'template_id' => $template?->id,
                'body' => $body,
                'status' => 'queued',
                'metadata' => array_filter([
                    'template_variables' => $template ? $variables : null,
                    'source' => $data['source'] ?? 'manual',
                    'invoice_id' => isset($data['invoice']) ? $data['invoice']->id : null,
                ]),
            ]);

            $conversation->update(['last_message_at' => now(), 'status' => 'open']);

            DB::afterCommit(fn () => SendWhatsAppMessage::dispatch($message->id));

            return $message->load('customer');
        });
    }

    /**
     * Re-queue a failed message.
     */
    public function retryMessage(Message $message): Message
    {
        if ($message->status !== 'failed') {
            throw ValidationException::withMessages(['status' => ['Only failed messages can be retried.']]);
        }

        $message->update(['status' => 'queued', 'error_message' => null]);
        SendWhatsAppMessage::dispatch($message->id);

        return $message;
    }

    /**
     * Find or open the customer's conversation on the business's active connection.
     */
    public function conversationFor(Customer $customer): Conversation
    {
        $existing = $customer->conversations()->latest('last_message_at')->first();
        if ($existing) {
            return $existing;
        }

        $connection = WhatsAppConnection::query()->active()->latest()->first();
        if (!$connection) {
            throw ValidationException::withMessages(['connection' => ['Connect a WhatsApp Business account before sending messages.']]);
        }

        return Conversation::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'whatsapp_connection_id' => $connection->id],
            ['status' => 'open'],
        );
    }

    /**
     * Placeholder values every template can rely on.
     */
    public function defaultVariables(Customer $customer, ?Invoice $invoice = null): array
    {
        $business = Business::query()->find(TenantManager::businessId());
        $currency = $business?->currency ?? '';
        $money = fn ($v) => trim($currency . ' ' . number_format((float) $v, 2));

        $vars = [
            'customer_name' => $customer->name,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'customer_business' => $customer->business_name,
            'business_name' => $business?->name,
            'company' => $business?->name,
            'currency' => $currency,
        ];

        if ($invoice) {
            $vars += [
                'invoice_number' => $invoice->invoice_number,
                'amount' => $money($invoice->total),
                'total' => $money($invoice->total),
                'amount_paid' => $money($invoice->amount_paid),
                'balance_due' => $money($invoice->balance_due),
                'balance' => $money($invoice->balance_due),
                'due_date' => $invoice->due_date?->format('M d, Y') ?? '',
                'days_overdue' => (string) $invoice->daysOverdue(),
            ];
        }

        return $vars;
    }

    /**
     * Apply a delivery status reported by a webhook. Statuses never move backwards.
     */
    public function applyStatus(string $whatsappMessageId, string $status, ?string $errorMessage = null): ?Message
    {
        $message = Message::query()->where('whatsapp_message_id', $whatsappMessageId)->first();
        if (!$message || !isset(Message::STATUS_RANK[$status])) {
            return null;
        }

        $current = Message::STATUS_RANK[$message->status] ?? 0;
        if (Message::STATUS_RANK[$status] < $current && $status !== 'failed') {
            return $message;
        }

        $updates = ['status' => $status];
        if (in_array($status, ['sent', 'delivered', 'read'], true)) {
            $updates[$status . '_at'] = $message->{$status . '_at'} ?? now();
        }
        if ($status === 'failed') {
            $updates['error_message'] = $errorMessage;
        }

        $message->update($updates);

        if ($status === 'failed') {
            $this->notifications->messageFailed($message);
        }

        // Resolved lazily: BroadcastService depends on this service.
        app(BroadcastService::class)->syncRecipientStatus($message);

        return $message;
    }

    /**
     * Store an inbound message. Idempotent on the WhatsApp message id.
     */
    public function handleIncoming(WhatsAppConnection $connection, array $incoming): ?Message
    {
        $from = preg_replace('/\D+/', '', (string) data_get($incoming, 'from'));
        $waId = data_get($incoming, 'id');
        if (!$from || !$waId) {
            return null;
        }

        $customer = Customer::query()->where('phone_normalized', $from)->first();

        if (!$customer) {
            // Unknown senders become customers so the conversation is not lost.
            $customer = Customer::create(['name' => '+' . $from, 'phone' => '+' . $from, 'category' => 'walk-in']);
        }

        $conversation = Conversation::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'whatsapp_connection_id' => $connection->id],
            ['status' => 'open'],
        );

        $type = data_get($incoming, 'type', 'text');
        $body = data_get($incoming, 'text.body')
            ?? data_get($incoming, 'button.text')
            ?? data_get($incoming, 'interactive.button_reply.title')
            ?? data_get($incoming, "{$type}.caption")
            ?? "[{$type}]";

        $message = Message::query()->firstOrCreate(
            ['whatsapp_message_id' => $waId],
            [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'type' => $type,
                'body' => $body,
                'status' => 'delivered',
                'sent_at' => now(),
                'delivered_at' => now(),
                'metadata' => ['raw_type' => $type],
            ],
        );

        $conversation->update(['last_message_at' => now(), 'status' => 'open']);

        return $message;
    }
}
