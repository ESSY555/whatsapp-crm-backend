<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\WhatsApp\StoreConnectionRequest;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Carbon;

class WhatsAppConnectionController extends ApiController
{
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
                'status' => 'active', 'connected_at' => Carbon::now(), 'last_verified_at' => Carbon::now(),
            ],
        );
        return $this->successResponse('WhatsApp connection saved successfully.', $connection, 201);
    }

    public function destroy(int $connection)
    {
        $connection = WhatsAppConnection::query()->findOrFail($connection);
        $connection->update(['status' => 'disconnected', 'access_token_encrypted' => 'revoked']);
        return $this->successResponse('WhatsApp connection disconnected successfully.');
    }
}
