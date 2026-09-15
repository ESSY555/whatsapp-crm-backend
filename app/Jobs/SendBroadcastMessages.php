<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Services\BroadcastService;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Processes a broadcast one batch at a time, re-queueing itself until every
 * recipient has been handled. Pausing the broadcast stops the chain.
 */
class SendBroadcastMessages implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 3;

    public function __construct(public int $broadcastId)
    {
    }

    public function handle(BroadcastService $service): void
    {
        $broadcast = Broadcast::withoutGlobalScopes()->find($this->broadcastId);
        if (!$broadcast) {
            Log::warning("Broadcast {$this->broadcastId} not found");
            return;
        }

        TenantManager::runAs($broadcast->business_id, function () use ($service, $broadcast) {
            $hasMore = $service->processBatch($broadcast->fresh());

            if ($hasMore && $broadcast->fresh()->status === 'processing') {
                self::dispatch($this->broadcastId)->delay(now()->addSeconds(2));
            }
        });
    }
}
