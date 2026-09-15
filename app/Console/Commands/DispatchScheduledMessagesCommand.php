<?php

namespace App\Console\Commands;

use App\Jobs\SendBroadcastMessages;
use App\Models\Broadcast;
use App\Services\ScheduledMessageService;
use Illuminate\Console\Command;

class DispatchScheduledMessagesCommand extends Command
{
    protected $signature = 'messages:dispatch-due';

    protected $description = 'Queue scheduled messages and scheduled broadcasts whose time has come';

    public function handle(ScheduledMessageService $scheduled): int
    {
        $messages = $scheduled->dispatchDue();

        $broadcasts = 0;
        Broadcast::withoutGlobalScopes()->due()->orderBy('scheduled_at')->limit(100)->each(function (Broadcast $broadcast) use (&$broadcasts) {
            $claimed = Broadcast::withoutGlobalScopes()->whereKey($broadcast->id)->where('status', 'scheduled')
                ->update(['status' => 'processing', 'started_at' => now()]);
            if ($claimed) {
                SendBroadcastMessages::dispatch($broadcast->id);
                $broadcasts++;
            }
        });

        $this->info("Dispatched {$messages} scheduled message(s) and {$broadcasts} broadcast(s).");

        return self::SUCCESS;
    }
}
