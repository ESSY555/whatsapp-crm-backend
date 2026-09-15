<?php

namespace App\Services;

use App\Models\Notification;
use App\Tenancy\TenantManager;

/**
 * In-app notifications for the current tenant. A notification with no user_id
 * is visible to every member of the business.
 */
class NotificationService
{
    public function notify(string $type, string $title, ?string $message = null, array $data = [], ?int $userId = null): ?Notification
    {
        if (!TenantManager::has()) {
            return null;
        }

        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data ?: null,
        ]);
    }

    public function paymentReceived(\App\Models\Payment $payment): ?Notification
    {
        $invoice = $payment->invoice;
        $customer = $payment->customer;

        return $this->notify(
            'payment_received',
            'Payment received',
            sprintf('%s paid %s on %s.', $customer?->name ?? 'A customer', number_format((float) $payment->amount, 2), $invoice?->invoice_number ?? 'an invoice'),
            ['payment_id' => $payment->id, 'invoice_id' => $payment->invoice_id, 'customer_id' => $payment->customer_id, 'amount' => (float) $payment->amount],
        );
    }

    public function invoiceOverdue(\App\Models\Invoice $invoice): ?Notification
    {
        return $this->notify(
            'invoice_overdue',
            'Invoice overdue',
            sprintf('%s for %s is %d day(s) past due.', $invoice->invoice_number, $invoice->customer?->name ?? 'a customer', $invoice->daysOverdue()),
            ['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'balance_due' => (float) $invoice->balance_due],
        );
    }

    public function messageFailed(\App\Models\Message $message): ?Notification
    {
        return $this->notify(
            'message_failed',
            'WhatsApp delivery failed',
            sprintf('Message to %s could not be delivered%s.', $message->customer?->name ?? 'a customer', $message->error_message ? ': ' . rtrim($message->error_message, '.') : ''),
            ['message_id' => $message->id, 'customer_id' => $message->customer_id, 'conversation_id' => $message->conversation_id],
        );
    }

    public function broadcastCompleted(\App\Models\Broadcast $broadcast): ?Notification
    {
        return $this->notify(
            'broadcast_completed',
            'Broadcast completed',
            sprintf('"%s" reached %d of %d recipients (%d failed).', $broadcast->name, $broadcast->sent_count, $broadcast->total_recipients, $broadcast->failed_count),
            ['broadcast_id' => $broadcast->id],
        );
    }

    public function scheduledMessageSent(\App\Models\ScheduledMessage $scheduled): ?Notification
    {
        return $this->notify(
            'scheduled_message_sent',
            'Scheduled message sent',
            sprintf('Scheduled message to %s was dispatched.', $scheduled->customer?->name ?? 'a customer'),
            ['scheduled_message_id' => $scheduled->id, 'customer_id' => $scheduled->customer_id],
        );
    }

    public function connectionFailure(\App\Models\WhatsAppConnection $connection, string $reason): ?Notification
    {
        return $this->notify(
            'whatsapp_connection_failure',
            'WhatsApp connection problem',
            sprintf('Connection %s reported an error: %s', $connection->phone_number_id, $reason),
            ['connection_id' => $connection->id],
        );
    }
}
