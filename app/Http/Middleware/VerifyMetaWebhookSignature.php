<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyMetaWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $signatureHeader = $request->header('X-Hub-Signature-256');

        if (!$signatureHeader) {
            Log::warning('WhatsApp Webhook: Missing signature header.');
            return response('Forbidden', 403);
        }

        $appSecret = config('services.whatsapp.app_secret');

        if (empty($appSecret)) {
            if (app()->environment('local')) {
                Log::warning('WhatsApp Webhook: WHATSAPP_APP_SECRET no configurado (solo local).');
                return $next($request);
            }
            Log::error('WhatsApp Webhook: Missing WHATSAPP_APP_SECRET in production.');
            return response('Forbidden', 403);
        }

        // The signature is formatted as "sha256=<hash>"
        $elements = explode('=', $signatureHeader);

        if (count($elements) !== 2 || strtolower($elements[0]) !== 'sha256') {
            Log::warning('WhatsApp Webhook: Invalid signature format.');
            return response('Forbidden', 403);
        }

        $signature = $elements[1];
        $payload = $request->getContent();

        $expectedSignature = hash_hmac('sha256', $payload, $appSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('WhatsApp Webhook: Signature mismatch.');
            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
