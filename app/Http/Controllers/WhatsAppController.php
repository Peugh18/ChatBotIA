<?php

namespace App\Http\Controllers;

use App\Services\MessageHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    protected $messageHandler;

    public function __construct(MessageHandler $messageHandler)
    {
        $this->messageHandler = $messageHandler;
    }

    public function verify(Request $request)
    {
        $mode = $request->input('hub_mode') ?? $request->input('hub.mode');
        $token = $request->input('hub_verify_token') ?? $request->input('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');

        if ($mode === 'subscribe' && $token === 'miguel') {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request)
    {
        $body = $request->all();
        
        // Log incoming messages for debugging
        Log::info('Incoming webhook message: ' . json_encode($body, JSON_PRETTY_PRINT));

        $entry = $body['entry'][0] ?? null;
        $changes = $entry['changes'][0] ?? null;
        $value = $changes['value'] ?? null;
        $message = $value['messages'][0] ?? null;
        $senderInfo = $value['contacts'][0] ?? null;

        if ($message) {
            $this->messageHandler->handleIncomingMessage($message, $senderInfo);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
