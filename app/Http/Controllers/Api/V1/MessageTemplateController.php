<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\WhatsApp\StoreTemplateRequest;
use App\Http\Requests\WhatsApp\UpdateTemplateRequest;
use App\Models\MessageTemplate;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class MessageTemplateController extends ApiController
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function index(Request $request)
    {
        $templates = MessageTemplate::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->when($request->string('search')->trim()->value(), function ($q, $search) {
                $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('content', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate(min($request->integer('per_page', 15), 100));

        return $this->successResponse('Message templates retrieved successfully.', $templates);
    }

    public function store(StoreTemplateRequest $request)
    {
        $template = MessageTemplate::create($request->validated());
        $this->audit->log('template.created', $template, null, null, $template->only('name', 'status', 'category'));

        return $this->successResponse('Message template created successfully.', $template, 201);
    }

    public function show(int $template)
    {
        $template = MessageTemplate::query()->findOrFail($template);

        return $this->successResponse('Message template retrieved successfully.', $template);
    }

    public function update(UpdateTemplateRequest $request, int $template)
    {
        $template = MessageTemplate::query()->findOrFail($template);
        $template->update($request->validated());
        $this->audit->logChanges('template.updated', $template);

        return $this->successResponse('Message template updated successfully.', $template->fresh());
    }

    public function destroy(int $template)
    {
        $template = MessageTemplate::query()->findOrFail($template);

        if ($template->reminderRulesCount() > 0) {
            return $this->errorResponse('This template is used by a reminder rule. Remove the rule first.', 422);
        }

        $this->audit->log('template.deleted', $template, null, $template->only('name', 'status'));
        $template->delete();

        return $this->successResponse('Message template deleted successfully.');
    }
}
