<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ReminderRule\StoreReminderRuleRequest;
use App\Http\Requests\ReminderRule\UpdateReminderRuleRequest;
use App\Models\ReminderLog;
use App\Models\ReminderRule;
use App\Services\ReminderService;
use Illuminate\Http\Request;

class ReminderRuleController extends ApiController
{
    public function __construct(private ReminderService $reminders)
    {
    }

    public function index(Request $request)
    {
        $rules = ReminderRule::query()
            ->with('template:id,name,status')
            ->withCount(['logs as sent_count' => fn ($q) => $q->where('status', 'sent')])
            ->when($request->filled('enabled'), fn ($q) => $q->where('enabled', $request->boolean('enabled')))
            ->when($request->string('search')->trim()->value(), fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('trigger_type')->orderBy('days_offset')
            ->paginate(min($request->integer('per_page', 50), 100));

        return $this->successResponse('Reminder rules retrieved successfully.', $rules);
    }

    public function store(StoreReminderRuleRequest $request)
    {
        $data = $request->validated();
        $data['days_offset'] = in_array($data['trigger_type'], ['on_due', 'overdue'], true) ? 0 : ($data['days_offset'] ?? 0);
        $data['enabled'] = $data['enabled'] ?? true;

        $rule = ReminderRule::create($data);

        return $this->successResponse('Reminder rule created successfully.', $rule->load('template:id,name,status'), 201);
    }

    public function show(int $reminder)
    {
        $rule = ReminderRule::query()->with('template')->findOrFail($reminder);

        return $this->successResponse('Reminder rule retrieved successfully.', $rule);
    }

    public function update(UpdateReminderRuleRequest $request, int $reminder)
    {
        $rule = ReminderRule::query()->findOrFail($reminder);
        $data = $request->validated();
        $type = $data['trigger_type'] ?? $rule->trigger_type;
        if (in_array($type, ['on_due', 'overdue'], true)) {
            $data['days_offset'] = 0;
        }
        $rule->update($data);

        return $this->successResponse('Reminder rule updated successfully.', $rule->fresh()->load('template:id,name,status'));
    }

    public function destroy(int $reminder)
    {
        $rule = ReminderRule::query()->findOrFail($reminder);
        $rule->delete();

        return $this->successResponse('Reminder rule deleted successfully.');
    }

    /**
     * Invoices that would be reminded if the engine ran right now.
     */
    public function preview()
    {
        return $this->successResponse('Reminder preview retrieved successfully.', $this->reminders->preview());
    }

    /**
     * Run the engine immediately for this business.
     */
    public function run()
    {
        return $this->successResponse('Reminders processed.', $this->reminders->run());
    }

    public function logs(Request $request)
    {
        $logs = ReminderLog::query()
            ->with(['rule:id,name,trigger_type,days_offset', 'invoice:id,invoice_number,balance_due', 'customer:id,name,business_name', 'message:id,status'])
            ->when($request->filled('invoice_id'), fn ($q) => $q->where('invoice_id', $request->integer('invoice_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return $this->successResponse('Reminder history retrieved successfully.', $logs);
    }

    public function triggerTypes()
    {
        return $this->successResponse('Trigger types retrieved successfully.', [
            'trigger_types' => [
                'before_due' => 'Days before due date',
                'on_due' => 'On the due date',
                'after_due' => 'Days after due date',
                'overdue' => 'Any time after the due date (once)',
            ],
        ]);
    }
}
