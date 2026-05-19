<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;

class ClientBotController extends Controller
{
    /**
     * Pauses the AI bot for the given client.
     */
    public function pauseBot(Client $client)
    {
        $client->update([
            'bot_paused_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'El bot ha sido pausado para este cliente.',
            'client' => $client->fresh(),
        ]);
    }

    /**
     * Resumes the AI bot for the given client.
     */
    public function resumeBot(Client $client)
    {
        $client->update([
            'bot_paused_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'El bot ha sido reanudado para este cliente.',
            'client' => $client->fresh(),
        ]);
    }
}
