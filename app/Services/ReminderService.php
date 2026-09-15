<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ReminderLog;
use App\Models\ReminderRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Automated payment reminders. For every enabled rule the engine finds the
 * invoices that qualify today, skips the ones already reminded by that rule,
 * queues a WhatsApp message and records the outcome in reminder_logs.
 */
class ReminderService
{
    public function __construct(private WhatsAppService $whatsapp)
    {
    }

    /**
     * Run all enabled rules for the current tenant. Returns a summary.
     */
    public function run(): array
    {
        $summary = ['rules' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        $rules = ReminderRule::query()->enabled()->with('template')->get();
        if ($rules->isEmpty()) {
            return $summary;
        }

        $invoices = Invoice::query()->open()->whereNotNull('due_date')->with('customer')->get();

        foreach ($rules as $rule) {
            $summary['rules']++;
            foreach ($invoices as $invoice) {
                if (!$rule->matchesInvoice($invoice)) {
                    continue;
                }
                if ($this->alreadySent($rule, $invoice)) {
                    $summary['skipped']++;
                    continue;
                }
                $log = $this->send($rule, $invoice);
                $summary[$log->status === 'sent' ? 'sent' : 'failed']++;
            }
        }

        return $summary;
    }

    /**
     * Send one reminder and log it. A duplicate for the same rule+invoice is refused.
     */
    public function send(ReminderRule $rule, Invoice $invoice): ReminderLog
    {
        if ($this->alreadySent($rule, $invoice)) {
            throw ValidationException::withMessages(['invoice_id' => ['A reminder for this rule was already sent.']]);
        }

        try {
            return DB::transaction(function () use ($rule, $invoice) {
                $message = $this->whatsapp->sendMessage([
                    'customer_id' => $invoice->customer_id,
                    'template_id' => $rule->template_id,
                    'invoice' => $invoice,
                    'source' => 'reminder',
                ]);
                $message->update(['metadata' => array_merge($message->metadata ?? [], ['reminder_rule_id' => $rule->id])]);

                return ReminderLog::create([
                    'reminder_rule_id' => $rule->id,
                    'invoice_id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'message_id' => $message->id,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            return ReminderLog::create([
                'reminder_rule_id' => $rule->id,
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'status' => 'failed',
            ]);
        }
    }

    public function alreadySent(ReminderRule $rule, Invoice $invoice): bool
    {
        return ReminderLog::query()
            ->where('reminder_rule_id', $rule->id)
            ->where('invoice_id', $invoice->id)
            ->where('status', 'sent')
            ->exists();
    }

    /**
     * Invoices each enabled rule would remind today (preview for the UI).
     */
    public function preview(): array
    {
        $rules = ReminderRule::query()->enabled()->get();
        $invoices = Invoice::query()->open()->whereNotNull('due_date')->with('customer:id,name,business_name,phone')->get();
        $due = [];

        foreach ($rules as $rule) {
            foreach ($invoices as $invoice) {
                if ($rule->matchesInvoice($invoice) && !$this->alreadySent($rule, $invoice)) {
                    $due[] = [
                        'rule_id' => $rule->id,
                        'rule_name' => $rule->name,
                        'invoice' => $invoice->only('id', 'invoice_number', 'due_date', 'balance_due', 'status'),
                        'customer' => $invoice->customer?->only('id', 'name', 'business_name', 'phone'),
                    ];
                }
            }
        }

        return $due;
    }
}
