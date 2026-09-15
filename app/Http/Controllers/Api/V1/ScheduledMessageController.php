<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ScheduledMessage\StoreScheduledMessageRequest;
use App\Http\Requests\ScheduledMessage\UpdateScheduledMessageRequest;
use App\Models\ScheduledMessage;
use App\Services\ScheduledMessageService;
use Illuminate\Http\Request;

class ScheduledMessageController extends ApiController
{
    public function __construct(private ScheduledMessageService $service)
    {
    }

    public function index(Request $request)
    {
        $messages = ScheduledMessage::query()
            ->with(['customer:id,name,business_name,phone', 'template:id,name'])
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from_date'), fn ($q) => $q->where('scheduled_at', '>=', $request->date('from_date')))
            ->when($request->filled('to_date'), fn ($q) => $q->where('scheduled_at', '<=', $request->date('to_date')->endOfDay()))
            ->orderByDesc('scheduled_at')
            ->paginate(min($request->integer('per_page', 15), 100));

        return $this->successResponse('Scheduled messages retrieved successfully.', $messages);
    }

    public function store(StoreScheduledMessageRequest $request)
    {
        $message = $this->service->create($request->validated());

        return $this->successResponse('Scheduled message created successfully.', $message, 201);
    }

    public function show(int $scheduledMessage)
    {
        $message = ScheduledMessage::query()->with(['customer', 'template'])->findOrFail($scheduledMessage);

        return $this->successResponse('Scheduled message retrieved successfully.', $message);
    }

    public function update(UpdateScheduledMessageRequest $request, int $scheduledMessage)
    {
        $message = $this->service->update(ScheduledMessage::query()->findOrFail($scheduledMessage), $request->validated());

        return $this->successResponse('Scheduled message updated successfully.', $message);
    }

    public function cancel(int $scheduledMessage)
    {
        $message = $this->service->cancel(ScheduledMessage::query()->findOrFail($scheduledMessage));

        return $this->successResponse('Scheduled message cancelled successfully.', $message);
    }

    public function destroy(int $scheduledMessage)
    {
        $message = ScheduledMessage::query()->findOrFail($scheduledMessage);

        if (!in_array($message->status, ['scheduled', 'cancelled', 'failed'], true)) {
            return $this->errorResponse('Only pending, cancelled or failed scheduled messages can be deleted.', 422);
        }

        $message->delete();

        return $this->successResponse('Scheduled message deleted successfully.');
    }

    public function stats()
    {
        return $this->successResponse('Scheduled message statistics retrieved successfully.', $this->service->stats());
    }
}
