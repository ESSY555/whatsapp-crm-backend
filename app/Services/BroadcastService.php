<?php

namespace App\Services;

use App\Jobs\SendBroadcastMessages;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Customer;
use App\Models\MessageTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Broadcasts fan one message out to many customers. Recipients are stored up
 * front; delivery happens in batches inside a queue job, never in a request.
 */
class BroadcastService
{
    public const BATCH_SIZE = 100;

    public function __construct(private WhatsAppService $whatsapp, private NotificationService $notifications, private QuotaService $quota)
    {
    }

    public function create(array $data, int $userId): Broadcast
    {
        return DB::transaction(function () use ($data, $userId) {
            $this->quota->assertBroadcastsAllowed();

            if (!empty($data['template_id'])) {
                $template = MessageTemplate::query()->findOrFail($data['template_id']);
                if (!$template->isApproved()) {
                    throw ValidationException::withMessages(['template_id' => ['Only approved templates can be broadcast.']]);
                }
            }

            $broadcast = Broadcast::create([
                'name' => $data['name'],
                'template_id' => $data['template_id'] ?? null,
                'content' => $data['content'] ?? null,
                'status' => 'draft',
                'created_by' => $userId,
            ]);

            $this->addRecipients($broadcast, $this->resolveCustomerIds($data));

            if (!empty($data['send_now'])) {
                $this->start($broadcast);
            } elseif (!empty($data['scheduled_at'])) {
                $this->schedule($broadcast, $data['scheduled_at']);
            }

            return $broadcast->fresh(['template']);
        });
    }

    /**
     * Turn customer_ids / group_ids / all_customers into a unique id list.
     */
    public function resolveCustomerIds(array $data): array
    {
        $ids = collect($data['customer_ids'] ?? []);

        if (!empty($data['group_ids'])) {
            $ids = $ids->merge(
                Customer::query()->whereHas('groups', fn ($q) => $q->whereIn('customer_groups.id', $data['group_ids']))->pluck('id'),
            );
        }
        if (!empty($data['all_customers'])) {
            $ids = $ids->merge(Customer::query()->where('status', 'active')->pluck('id'));
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function addRecipients(Broadcast $broadcast, array $customerIds): Broadcast
    {
        if ($broadcast->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Recipients can only be changed while a broadcast is a draft.']]);
        }

        return DB::transaction(function () use ($broadcast, $customerIds) {
            $validIds = Customer::query()->whereIn('id', $customerIds)->pluck('id')->all();
            $existing = $broadcast->recipients()->pluck('customer_id')->all();

            foreach (array_diff($validIds, $existing) as $customerId) {
                BroadcastRecipient::create([
                    'broadcast_id' => $broadcast->id,
                    'customer_id' => $customerId,
                    'status' => 'pending',
                ]);
            }

            $broadcast->update(['total_recipients' => $broadcast->recipients()->count()]);

            return $broadcast;
        });
    }

    public function schedule(Broadcast $broadcast, $scheduledAt): Broadcast
    {
        $this->assertHasRecipients($broadcast);
        if (!in_array($broadcast->status, ['draft', 'scheduled'], true)) {
            throw ValidationException::withMessages(['status' => ['Only draft broadcasts can be scheduled.']]);
        }
        $this->assertWithinPlan($broadcast);

        $broadcast->update(['status' => 'scheduled', 'scheduled_at' => $scheduledAt]);

        return $broadcast;
    }

    public function start(Broadcast $broadcast): Broadcast
    {
        $this->assertHasRecipients($broadcast);
        if (!in_array($broadcast->status, ['draft', 'scheduled', 'paused'], true)) {
            throw ValidationException::withMessages(['status' => ['This broadcast is already processing or finished.']]);
        }
        $this->assertWithinPlan($broadcast);

        $broadcast->update(['status' => 'processing', 'started_at' => $broadcast->started_at ?? now()]);
        DB::afterCommit(fn () => SendBroadcastMessages::dispatch($broadcast->id));

        return $broadcast;
    }

    /**
     * Broadcasts need a plan that includes them and enough marketing quota
     * for every pending recipient, checked up front so the user gets one
     * clear message instead of a half-sent blast.
     */
    private function assertWithinPlan(Broadcast $broadcast): void
    {
        $this->quota->assertBroadcastsAllowed();
        $pending = $broadcast->recipients()->pending()->count();
        if ($pending > 0) {
            $this->quota->assertCanSend('marketing', $pending);
        }
    }

    public function pause(Broadcast $broadcast): Broadcast
    {
        if ($broadcast->status !== 'processing') {
            throw ValidationException::withMessages(['status' => ['Only processing broadcasts can be paused.']]);
        }
        $broadcast->update(['status' => 'paused']);

        return $broadcast;
    }

    public function cancel(Broadcast $broadcast): Broadcast
    {
        if (!in_array($broadcast->status, ['draft', 'scheduled', 'paused'], true)) {
            throw ValidationException::withMessages(['status' => ['Only draft, scheduled or paused broadcasts can be cancelled.']]);
        }
        $broadcast->update(['status' => 'failed', 'completed_at' => now()]);

        return $broadcast;
    }

    /**
     * Send one batch of pending recipients. Returns true when more remain.
     */
    public function processBatch(Broadcast $broadcast, int $batchSize = self::BATCH_SIZE): bool
    {
        if ($broadcast->status !== 'processing') {
            return false;
        }

        $recipients = $broadcast->recipients()->pending()->with('customer')->limit($batchSize)->get();

        if ($recipients->isEmpty()) {
            $this->complete($broadcast);
            return false;
        }

        // Out of marketing quota: pause instead of failing every recipient so
        // the business can upgrade and resume.
        try {
            $this->quota->assertCanSend('marketing');
        } catch (ValidationException $e) {
            $broadcast->update(['status' => 'paused']);
            $this->notifications->notify('quota_exhausted', 'Broadcast paused', sprintf('"%s" was paused: %s', $broadcast->name, $e->validator->errors()->first()), ['broadcast_id' => $broadcast->id]);
            return false;
        }

        foreach ($recipients as $recipient) {
            if (!$recipient->customer) {
                $recipient->update(['status' => 'failed']);
                $broadcast->increment('failed_count');
                continue;
            }

            try {
                $message = $this->whatsapp->sendMessage([
                    'customer_id' => $recipient->customer_id,
                    'template_id' => $broadcast->template_id,
                    'body' => $broadcast->content,
                    'source' => 'broadcast',
                ]);
                $message->update(['metadata' => array_merge($message->metadata ?? [], ['broadcast_id' => $broadcast->id])]);

                $recipient->update(['status' => 'sent', 'message_id' => $message->id, 'sent_at' => now()]);
                $broadcast->increment('sent_count');
            } catch (Throwable $e) {
                $recipient->update(['status' => 'failed']);
                $broadcast->increment('failed_count');
            }
        }

        if ($broadcast->recipients()->pending()->exists()) {
            return true;
        }

        $this->complete($broadcast->fresh());

        return false;
    }

    public function complete(Broadcast $broadcast): Broadcast
    {
        $broadcast->update([
            'status' => 'completed',
            'completed_at' => now(),
            'sent_count' => $broadcast->recipients()->whereIn('status', ['sent', 'delivered', 'read'])->count(),
            'delivered_count' => $broadcast->recipients()->whereIn('status', ['delivered', 'read'])->count(),
            'failed_count' => $broadcast->recipients()->where('status', 'failed')->count(),
        ]);
        $this->notifications->broadcastCompleted($broadcast);

        return $broadcast;
    }

    /**
     * Keep recipient rows in step with message delivery status updates.
     */
    public function syncRecipientStatus(\App\Models\Message $message): void
    {
        $recipient = BroadcastRecipient::query()->where('message_id', $message->id)->first();
        if (!$recipient) {
            return;
        }

        $status = match ($message->status) {
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            'sent' => 'sent',
            default => null,
        };
        if (!$status || $recipient->status === $status) {
            return;
        }

        $recipient->update(['status' => $status]);

        $broadcast = $recipient->broadcast;
        if ($broadcast) {
            $broadcast->update([
                'sent_count' => $broadcast->recipients()->whereIn('status', ['sent', 'delivered', 'read'])->count(),
                'delivered_count' => $broadcast->recipients()->whereIn('status', ['delivered', 'read'])->count(),
                'failed_count' => $broadcast->recipients()->where('status', 'failed')->count(),
            ]);
        }
    }

    private function assertHasRecipients(Broadcast $broadcast): void
    {
        if ($broadcast->recipients()->count() === 0) {
            throw ValidationException::withMessages(['recipients' => ['Add at least one recipient before sending.']]);
        }
        if (!$broadcast->template_id && trim((string) $broadcast->content) === '') {
            throw ValidationException::withMessages(['content' => ['A message or template is required.']]);
        }
    }
}
