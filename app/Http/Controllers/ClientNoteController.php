<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientNote;
use Illuminate\Http\Request;

class ClientNoteController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        ClientNote::create([
            'client_id' => $client->id,
            'user_id'   => $request->user()?->id,
            'body'      => $validated['body'],
        ]);

        return back()->with('success', 'Nota agregada.');
    }

    public function destroy(ClientNote $note)
    {
        $note->delete();
        return back()->with('success', 'Nota eliminada.');
    }
}
