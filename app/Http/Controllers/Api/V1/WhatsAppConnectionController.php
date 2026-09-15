<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\WhatsApp\StoreConnectionRequest;
use App\Models\WhatsAppConnection;
use App\Services\AuditLogger;
use App\Services\WhatsAppCloudApi;
use Illuminate\Support\Carbon;
use Throwable;

class WhatsAppConnectionController extends ApiController
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function index()
    {
        return $this->successResponse('WhatsApp connections retrieved successfully.', WhatsAppConnection::query()->latest()->get());
    }

    public function store(StoreConnectionRequest $request)
    {
        $connection = WhatsAppConnection::updateOrCreate(
            ['phone_number_id' => $request->validated('phone_number_id')],
            [
                'business_account_id' => $request->validated('business_account_id'),
                'access_token_encrypted' => $request->validated('access_token'),
                'status' => 'active',
                'connected_at' => Carbon::now(),
                'last_verified_at' => null,
            ],
        );

        $this->audit->log(
            $connection->wasRecentlyCreated ? 'whatsapp_connection.created' : 'whatsapp_connection.updated',
            $connection, null, null, $connection->only('phone_number_id', 'business_account_id', 'status'),
        );

        return $this->successResponse('WhatsApp connection saved successfully.', $connection, 201);
    }

    /**
     * Check the stored credentials against the Cloud API.
     */
    public function verify(int $connection, WhatsAppCloudApi $api)
    {
        $connection = WhatsAppConnection::query()->findOrFail($connection);

        try {
            $ok = $api->verify($connection);
        } catch (Throwable) {
            $ok = false;
        }

        $connection->update($ok
            ? ['status' => 'active', 'last_verified_at' => now()]
            : ['status' => 'error']);

        return $ok
            ? $this->successResponse('WhatsApp connection verified.', $connection->fresh())
            : $this->errorResponse('WhatsApp rejected the stored credentials. Reconnect with a fresh access token.', 422);
    }

    public function destroy(int $connection)
    {
        $connection = WhatsAppConnection::query()->findOrFail($connection);
        $connection->update(['status' => 'disconnected', 'access_token_encrypted' => 'revoked']);
        $this->audit->log('whatsapp_connection.disconnected', $connection, null, null, ['status' => 'disconnected']);

        return $this->successResponse('WhatsApp connection disconnected successfully.');
    }
}
