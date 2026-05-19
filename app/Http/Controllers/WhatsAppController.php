<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
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

            // Procesar en cola "default" (el worker de dev solo escuchaba default).
            // En local, opcionalmente síncrono para no depender del worker.
            if (config('services.whatsapp.sync_webhooks', false)) {
                ProcessWhatsAppWebhook::dispatchSync($body);
            } else {
                ProcessWhatsAppWebhook::dispatch($body);
            }

            return response('EVENT_RECEIVED', 200);
        } catch (\Exception $e) {
            Log::error('WhatsApp Controller Error: ' . $e->getMessage());
            return response('INTERNAL_ERROR', 500);
        }
    }
}
