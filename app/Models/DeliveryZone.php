<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeliveryZone extends Model
{
    protected $fillable = [
        'district', 'slug', 'motorizado_cost', 'shalom_cost', 'region', 'active', 'notes',
    ];

    protected $casts = [
        'motorizado_cost' => 'decimal:2',
        'shalom_cost'     => 'decimal:2',
        'active'          => 'boolean',
    ];

    /** Normalize a district name to its slug form (ASCII, lowercase, dashes). */
    public static function makeSlug(string $name): string
    {
        return Str::slug(trim($name));
    }

    /**
     * Fuzzy-find a zone by a free-text district name.
     * Returns null if not found.
     */
    public static function findByName(?string $name): ?self
    {
        if (!$name) return null;
        $slug = self::makeSlug($name);
        if ($slug === '') return null;

        // Exact slug match first
        $exact = self::where('slug', $slug)->where('active', true)->first();
        if ($exact) return $exact;

        // Fallback: contains either direction
        return self::where('active', true)
            ->where(function ($q) use ($slug) {
                $q->where('slug', 'like', "%{$slug}%")
                  ->orWhereRaw('? like CONCAT(\'%\', slug, \'%\')', [$slug]);
            })
            ->first();
    }
}
