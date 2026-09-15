<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Broadcast\AddRecipientsRequest;
use App\Http\Requests\Broadcast\StoreBroadcastRequest;
use App\Models\Broadcast;
use App\Services\BroadcastService;
use Illuminate\Http\Request;

class BroadcastController extends ApiController
{
    public function __construct(private BroadcastService $service)
    {
    }

    public function index(Request $request)
    {
        $broadcasts = Broadcast::query()
            ->with(['template:id,name', 'creator:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->string('search')->trim()->value(), fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 15), 100));

        return $this->successResponse('Broadcasts retrieved successfully.', $broadcasts);
    }

    public function store(StoreBroadcastRequest $request)
    {
        $broadcast = $this->service->create($request->validated(), $request->user()->id);

        return $this->successResponse('Broadcast created successfully.', $broadcast, 201);
    }

    public function show(Request $request, int $broadcast)
    {
        $broadcast = Broadcast::query()->with(['template', 'creator:id,name'])->findOrFail($broadcast);

        $recipients = $broadcast->recipients()
            ->with(['customer:id,name,business_name,phone', 'message:id,status,error_message,sent_at'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        $data = $broadcast->toArray();
        $data['stats'] = $broadcast->stats();
        $data['recipients'] = $recipients;

        return $this->successResponse('Broadcast retrieved successfully.', $data);
    }

    public function addRecipients(AddRecipientsRequest $request, int $broadcast)
    {
        $broadcast = Broadcast::query()->findOrFail($broadcast);
        $broadcast = $this->service->addRecipients($broadcast, $this->service->resolveCustomerIds($request->validated()));

        return $this->successResponse('Recipients added successfully.', $broadcast->fresh());
    }

    public function schedule(Request $request, int $broadcast)
    {
        $validated = $request->validate(['scheduled_at' => ['required', 'date', 'after:now']]);
        $broadcast = $this->service->schedule(Broadcast::query()->findOrFail($broadcast), $validated['scheduled_at']);

        return $this->successResponse('Broadcast scheduled successfully.', $broadcast->fresh());
    }

    public function start(int $broadcast)
    {
        $broadcast = $this->service->start(Broadcast::query()->findOrFail($broadcast));

        return $this->successResponse('Broadcast started. Messages are being sent.', $broadcast->fresh());
    }

    public function pause(int $broadcast)
    {
        $broadcast = $this->service->pause(Broadcast::query()->findOrFail($broadcast));

        return $this->successResponse('Broadcast paused.', $broadcast->fresh());
    }

    public function cancel(int $broadcast)
    {
        $broadcast = $this->service->cancel(Broadcast::query()->findOrFail($broadcast));

        return $this->successResponse('Broadcast cancelled.', $broadcast->fresh());
    }

    public function destroy(int $broadcast)
    {
        $broadcast = Broadcast::query()->findOrFail($broadcast);

        if ($broadcast->status !== 'draft') {
            return $this->errorResponse('Only draft broadcasts can be deleted.', 422);
        }

        $broadcast->recipients()->delete();
        $broadcast->delete();

        return $this->successResponse('Broadcast deleted successfully.');
    }
}
