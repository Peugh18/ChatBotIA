<?php

namespace App\Http\Controllers;

use App\Models\DeliveryZone;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeliveryZoneController extends Controller
{
    public function index()
    {
        return Inertia::render('DeliveryZones', [
            'deliveryZones' => DeliveryZone::orderBy('district')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'district' => 'required|string|max:120|unique:delivery_zones,district',
            'motorizado_cost' => 'required|numeric|min:0|max:9999',
            'shalom_cost' => 'nullable|numeric|min:0|max:9999',
            'region' => 'nullable|string|max:60',
            'active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $validated['slug'] = DeliveryZone::makeSlug($validated['district']);
        $validated['shalom_cost'] = $validated['shalom_cost'] ?? 10;
        $validated['region'] = $validated['region'] ?? 'Lima';
        $validated['active'] = $request->boolean('active', true);

        DeliveryZone::create($validated);

        return back()->with('success', 'Tarifa de delivery creada.');
    }

    public function update(Request $request, DeliveryZone $deliveryZone)
    {
        $validated = $request->validate([
            'district' => 'required|string|max:120|unique:delivery_zones,district,' . $deliveryZone->id,
            'motorizado_cost' => 'required|numeric|min:0|max:9999',
            'shalom_cost' => 'nullable|numeric|min:0|max:9999',
            'region' => 'nullable|string|max:60',
            'active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $validated['slug'] = DeliveryZone::makeSlug($validated['district']);
        $validated['shalom_cost'] = $validated['shalom_cost'] ?? 10;
        $validated['region'] = $validated['region'] ?? 'Lima';
        $validated['active'] = $request->boolean('active', true);

        $deliveryZone->update($validated);

        return back()->with('success', 'Tarifa de delivery actualizada.');
    }

    public function destroy(DeliveryZone $deliveryZone)
    {
        $deliveryZone->delete();

        return back()->with('success', 'Tarifa de delivery eliminada.');
    }
}
