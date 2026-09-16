<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\MessageUsage;
use App\Models\Plan;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan enforcement for outbound WhatsApp. Every send path funnels through
 * WhatsAppService::sendMessage(), so a single check here covers manual
 * messages, reminders, scheduled messages and broadcasts.
 */
class QuotaService
{
    public const CATEGORIES = ['utility', 'marketing', 'service'];

    /** Template categories that count as marketing on the Meta rate card. */
    private const MARKETING_TEMPLATE_CATEGORIES = ['marketing', 'promotion', 'promotional', 'promo', 'campaign'];

    public function __construct(private BillingService $billing, private NotificationService $notifications)
    {
    }

    /**
     * Which bucket a message will be billed in.
     *
     * - A reply inside the 24-hour customer service window is free.
     * - Broadcasts and marketing-category templates are marketing.
     * - Everything else (invoices, reminders, receipts) is utility.
     */
    public function categoryFor(?Conversation $conversation, ?MessageTemplate $template, string $source = 'manual'): string
    {
        if ($conversation && $this->inServiceWindow($conversation)) {
            return 'service';
        }

        if ($source === 'broadcast') {
            return 'marketing';
        }

        $category = strtolower((string) ($template?->category ?? ''));
        if ($category !== '' && in_array($category, self::MARKETING_TEMPLATE_CATEGORIES, true)) {
            return 'marketing';
        }

        return 'utility';
    }

    /**
     * Throw a validation error if the current tenant cannot send `$count`
     * more messages of this category.
     */
    public function assertCanSend(string $category, int $count = 1): void
    {
        $business = $this->business();
        if (!$business) {
            return;
        }

        $plan = $this->billing->planFor($business);
        if (!$plan) {
            throw ValidationException::withMessages(['plan' => [$this->noPlanMessage($business)]]);
        }

        $remaining = $this->remaining($business, $plan, $category);
        if ($remaining !== null && $remaining < $count) {
            $label = $category === 'marketing' ? 'marketing' : 'utility';
            $message = $remaining === 0
                ? sprintf('You have used all %d %s messages on your %s plan this month. Upgrade your plan to keep sending.', $plan->quotaFor($category), $label, $plan->name)
                : sprintf('This needs %d %s messages but only %d remain on your %s plan this month.', $count, $label, $remaining, $plan->name);
            $this->notifyExhausted($business, $category, $plan);
            throw ValidationException::withMessages(['plan' => [$message]]);
        }
    }

    public function assertBroadcastsAllowed(): void
    {
        $business = $this->business();
        if (!$business) {
            return;
        }

        $plan = $this->billing->planFor($business);
        if (!$plan) {
            throw ValidationException::withMessages(['plan' => [$this->noPlanMessage($business)]]);
        }
        if (!$plan->allows_broadcasts) {
            $reason = $plan->code === 'trial'
                ? 'Broadcasts are not available during the free trial. Subscribe to a plan that includes broadcasts.'
                : sprintf('Your %s plan does not include broadcasts. Upgrade to send bulk messages.', $plan->name);
            throw ValidationException::withMessages(['plan' => [$reason]]);
        }
    }

    /**
     * Messages left this month for a category; null means unlimited.
     */
    public function remaining(Business $business, Plan $plan, string $category): ?int
    {
        $quota = $plan->quotaFor($category);
        if ($quota === null) {
            return null;
        }

        $sent = (int) MessageUsage::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('period_start', MessageUsage::currentPeriod())
            ->where('category', $category)
            ->value('sent_count');

        return max(0, $quota - $sent);
    }

    /**
     * Count a message that actually left through the Cloud API. Idempotent
     * per message via charged_at.
     */
    public function recordSent(Message $message): void
    {
        if ($message->charged_at || $message->direction !== 'outbound') {
            return;
        }

        $this->bump($message, 'sent_count');
        $message->forceFill(['charged_at' => now()])->save();
    }

    public function recordFailed(Message $message): void
    {
        if ($message->direction !== 'outbound') {
            return;
        }
        $this->bump($message, 'failed_count');
    }

    private function bump(Message $message, string $column): void
    {
        $category = $message->billing_category ?: 'utility';
        $period = MessageUsage::currentPeriod();

        DB::transaction(function () use ($message, $category, $period, $column) {
            $row = MessageUsage::withoutGlobalScopes()->firstOrCreate(
                ['business_id' => $message->business_id, 'period_start' => $period, 'category' => $category],
                ['sent_count' => 0, 'failed_count' => 0],
            );
            $row->increment($column);
        });
    }

    private function inServiceWindow(Conversation $conversation): bool
    {
        return Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
    }

    private function business(): ?Business
    {
        $id = TenantManager::businessId();
        return $id ? Business::query()->find($id) : null;
    }

    private function noPlanMessage(Business $business): string
    {
        return $business->trial_ends_at
            ? 'Your free trial has ended. Upload a payment receipt in Settings → Plan & billing to keep sending messages.'
            : 'No active plan. Upload a payment receipt in Settings → Plan & billing to start sending messages.';
    }

    /** One in-app heads-up per business, category and month. */
    private function notifyExhausted(Business $business, string $category, Plan $plan): void
    {
        $alreadySent = \App\Models\Notification::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('type', 'quota_exhausted')
            ->where('created_at', '>=', now()->startOfMonth())
            ->where('data->category', $category)
            ->exists();
        if ($alreadySent) {
            return;
        }

        $this->notifications->notify(
            'quota_exhausted',
            'Message limit reached',
            sprintf('You have reached the %s message limit on your %s plan for this month.', $category, $plan->name),
            ['category' => $category, 'plan' => $plan->code],
        );
    }
}
