<?php

namespace App\Jobs;

use App\Models\WhatsAppConnection;
use App\Services\WhatsAppService;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Processes one WhatsApp webhook payload. Each entry/change is resolved to a
 * tenant through the receiving phone number id, so cross-tenant leakage is
 * impossible even though the webhook itself is unauthenticated.
 */
class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public array $payload)
    {
    }

    public function handle(WhatsAppService $service): void
    {
        foreach (data_get($this->payload, 'entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $value = data_get($change, 'value', []);
                $phoneId = data_get($value, 'metadata.phone_number_id');
                if (!$phoneId) {
                    continue;
                }

                $connection = WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', $phoneId)->first();
                if (!$connection) {
                    Log::warning("WhatsApp webhook for unknown phone_number_id {$phoneId}");
                    continue;
                }

                TenantManager::runAs($connection->business_id, function () use ($service, $connection, $value) {
                    foreach (data_get($value, 'messages', []) as $incoming) {
                        $service->handleIncoming($connection, $incoming);
                    }

                    foreach (data_get($value, 'statuses', []) as $status) {
                        $service->applyStatus(
                            (string) data_get($status, 'id'),
                            (string) data_get($status, 'status'),
                            data_get($status, 'errors.0.title') ?? data_get($status, 'errors.0.message'),
                        );
                    }
                });
            }
        }
    }
}
