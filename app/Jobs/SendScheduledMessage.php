<?php

namespace App\Jobs;

use App\Models\ScheduledMessage;
use App\Services\ScheduledMessageService;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendScheduledMessage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(public int $scheduledMessageId)
    {
    }

    public function handle(ScheduledMessageService $service): void
    {
        $scheduled = ScheduledMessage::withoutGlobalScopes()->find($this->scheduledMessageId);
        if (!$scheduled) {
            return;
        }

        TenantManager::runAs($scheduled->business_id, fn () => $service->send($scheduled));
    }
}
