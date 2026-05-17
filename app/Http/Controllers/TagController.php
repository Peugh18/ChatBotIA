<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:50|unique:tags,name',
            'color' => 'nullable|string|max:9',
        ]);

        Tag::create([
            'name'  => $validated['name'],
            'color' => $validated['color'] ?? '#00a884',
        ]);

        return back()->with('success', 'Etiqueta creada.');
    }

    public function destroy(Tag $tag)
    {
        $tag->delete();
        return back()->with('success', 'Etiqueta eliminada.');
    }

    /** Attach/detach a tag from a client (toggle). */
    public function toggle(Request $request, Client $client)
    {
        $validated = $request->validate([
            'tag_id' => 'required|exists:tags,id',
        ]);

        $client->tags()->toggle($validated['tag_id']);

        return back()->with('success', 'Etiquetas actualizadas.');
    }
}
