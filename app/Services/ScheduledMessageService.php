<?php

namespace App\Services;

use App\Jobs\SendScheduledMessage;
use App\Models\ScheduledMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ScheduledMessageService
{
    public function __construct(private WhatsAppService $whatsapp, private NotificationService $notifications)
    {
    }

    public function create(array $data): ScheduledMessage
    {
        return ScheduledMessage::create([
            'customer_id' => $data['customer_id'],
            'template_id' => $data['template_id'] ?? null,
            'content' => $data['content'] ?? '',
            'scheduled_at' => $data['scheduled_at'],
            'status' => 'scheduled',
        ])->load(['customer', 'template']);
    }

    public function update(ScheduledMessage $scheduled, array $data): ScheduledMessage
    {
        if (!$scheduled->isPending()) {
            throw ValidationException::withMessages(['status' => ['Only pending scheduled messages can be changed.']]);
        }

        $scheduled->update(array_intersect_key($data, array_flip(['scheduled_at', 'content', 'template_id'])));

        return $scheduled->fresh(['customer', 'template']);
    }

    public function cancel(ScheduledMessage $scheduled): ScheduledMessage
    {
        if (!$scheduled->isPending()) {
            throw ValidationException::withMessages(['status' => ['Only pending scheduled messages can be cancelled.']]);
        }

        $scheduled->update(['status' => 'cancelled']);

        return $scheduled->fresh(['customer', 'template']);
    }

    /**
     * Queue jobs for every due message. Called by the scheduler every minute.
     */
    public function dispatchDue(): int
    {
        $count = 0;

        ScheduledMessage::withoutGlobalScopes()->due()->orderBy('scheduled_at')->limit(500)
            ->each(function (ScheduledMessage $scheduled) use (&$count) {
                // Claim the row first so a slow queue cannot dispatch it twice.
                $claimed = ScheduledMessage::withoutGlobalScopes()
                    ->whereKey($scheduled->id)->where('status', 'scheduled')
                    ->update(['status' => 'processing']);
                if ($claimed) {
                    SendScheduledMessage::dispatch($scheduled->id);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Deliver one scheduled message. Must run inside the tenant context.
     */
    public function send(ScheduledMessage $scheduled): bool
    {
        if (!in_array($scheduled->status, ['scheduled', 'processing'], true)) {
            return false;
        }

        try {
            $message = DB::transaction(function () use ($scheduled) {
                $scheduled->update(['status' => 'processing']);

                return $this->whatsapp->sendMessage([
                    'customer_id' => $scheduled->customer_id,
                    'template_id' => $scheduled->template_id,
                    'body' => $scheduled->content,
                    'source' => 'scheduled',
                ]);
            });

            $scheduled->update(['status' => 'sent', 'sent_at' => now(), 'error_message' => null]);
            $message->update(['metadata' => array_merge($message->metadata ?? [], ['scheduled_message_id' => $scheduled->id])]);
            $this->notifications->scheduledMessageSent($scheduled);

            return true;
        } catch (Throwable $e) {
            $reason = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage();
            $scheduled->update(['status' => 'failed', 'failed_at' => now(), 'error_message' => $reason]);

            return false;
        }
    }

    public function stats(): array
    {
        $counts = ScheduledMessage::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'scheduled' => (int) ($counts['scheduled'] ?? 0),
            'processing' => (int) ($counts['processing'] ?? 0),
            'sent' => (int) ($counts['sent'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
        ];
    }
}
