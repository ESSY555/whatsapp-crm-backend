<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\WhatsApp\SendMessageRequest;
use App\Models\Message;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class MessageController extends ApiController
{
    public function __construct(private WhatsAppService $whatsapp)
    {
    }

    /**
     * Search messages across the business by customer, conversation, status, direction or date.
     */
    public function index(Request $request)
    {
        $messages = Message::query()
            ->with('customer:id,name,business_name,phone')
            ->when($request->filled('conversation_id'), fn ($q) => $q->where('conversation_id', $request->integer('conversation_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from_date')))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to_date')))
            ->when($request->string('search')->trim()->value(), fn ($q, $search) => $q->where('body', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return $this->successResponse('Messages retrieved successfully.', $messages);
    }

    public function store(SendMessageRequest $request)
    {
        $message = $this->whatsapp->sendMessage($request->validated());

        return $this->successResponse('Message queued for delivery.', $message, 201);
    }

    public function show(int $message)
    {
        $message = Message::query()->with(['customer', 'conversation'])->findOrFail($message);

        return $this->successResponse('Message retrieved successfully.', $message);
    }

    public function retry(int $message)
    {
        $message = Message::query()->findOrFail($message);

        if ($message->status !== 'failed') {
            return $this->errorResponse('Only failed messages can be retried.', 422);
        }

        $this->whatsapp->retryMessage($message);

        return $this->successResponse('Message retry queued successfully.', $message->refresh());
    }
}
