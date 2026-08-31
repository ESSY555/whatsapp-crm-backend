<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\ProcessWhatsAppWebhook;
use Illuminate\Http\Request;

class WhatsAppWebhookController extends ApiController
{
    public function verify(Request $request)
    {
        if ($request->query('hub_verify_token') !== config('services.whatsapp.verify_token')) {
            return response('Invalid verification token.', 403);
        }
        return response($request->query('hub_challenge', ''), 200);
    }

    public function receive(Request $request)
    {
        ProcessWhatsAppWebhook::dispatch($request->all());
        return response()->json(['success' => true], 202);
    }
}
