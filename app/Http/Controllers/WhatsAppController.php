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

        $verifyToken = config('services.whatsapp.webhook_verify_token');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request)
    {
        try {
            // CRITICAL FIX: Use raw JSON body to bypass Laravel's normalization
            // depth limit ("Over 9 levels deep, aborting normalization") which
            // was corrupting nested webhook data and returning the literal
            // string instead of the actual message text.
            $rawBody = $request->getContent();
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            Log::info('WhatsApp Webhook: ' . $rawBody);

            $entry = $body['entry'][0] ?? null;
            $changes = $entry['changes'][0] ?? null;
            $value = $changes['value'] ?? null;
            $messageData = $value['messages'][0] ?? null;
            $contactData = $value['contacts'][0] ?? null;

            if ($messageData) {
                $this->messageHandler->process($messageData, $contactData);
            }

            return response('EVENT_RECEIVED', 200);
        } catch (\Exception $e) {
            Log::error('WhatsApp Controller Error: ' . $e->getMessage());
            return response('INTERNAL_ERROR', 500);
        }
    }
}
