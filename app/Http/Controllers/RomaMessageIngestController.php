<?php

namespace App\Http\Controllers;

use App\Services\Integration\RomaApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recibe mensajes desde roma-api (ngrok) cuando el webhook de Meta apunta allí.
 */
class RomaMessageIngestController extends Controller
{
    public function ingest(Request $request, RomaApiService $romaApi)
    {
        $expected = config('services.roma_api.sync_token');
        if ($expected && $request->header('X-Roma-Sync-Token') !== $expected) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'wa_id' => 'required|string|max:255',
            'sender_phone' => 'required|string|max:32',
            'message_body' => 'required|string|max:8000',
            'direction' => 'required|in:inbound,outbound',
        ]);

        $result = $romaApi->importLog($validated);

        if ($result === 'invalid') {
            return response()->json(['error' => 'Invalid payload'], 422);
        }

        Log::info('roma-api ingest', ['wa_id' => $validated['wa_id'], 'result' => $result]);

        return response()->json(['status' => $result], 200);
    }
}
