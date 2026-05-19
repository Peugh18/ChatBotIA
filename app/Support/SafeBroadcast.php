<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Broadcasting must never break WhatsApp message processing.
 */
class SafeBroadcast
{
    public static function event(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('Broadcast skipped (non-fatal): ' . $e->getMessage());
        }
    }
}
