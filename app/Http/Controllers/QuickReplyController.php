<?php

namespace App\Http\Controllers;

use App\Models\QuickReply;
use Illuminate\Http\Request;

class QuickReplyController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'shortcut' => 'required|string|max:30|unique:quick_replies,shortcut',
            'title'    => 'required|string|max:100',
            'body'     => 'required|string|max:2000',
        ]);
        QuickReply::create($validated);
        return back()->with('success', 'Atajo creado.');
    }

    public function update(Request $request, QuickReply $quickReply)
    {
        $validated = $request->validate([
            'shortcut' => 'required|string|max:30|unique:quick_replies,shortcut,' . $quickReply->id,
            'title'    => 'required|string|max:100',
            'body'     => 'required|string|max:2000',
        ]);
        $quickReply->update($validated);
        return back()->with('success', 'Atajo actualizado.');
    }

    public function destroy(QuickReply $quickReply)
    {
        $quickReply->delete();
        return back()->with('success', 'Atajo eliminado.');
    }
}
