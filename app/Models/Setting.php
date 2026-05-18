<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public $timestamps = true;

    /**
     * Get a setting value from cache or DB.
     */
    public static function get(string $key, $default = null): mixed
    {
        return Cache::remember("setting.{$key}", 300, function () use ($key, $default) {
            $record = static::where('key', $key)->first();
            return $record ? $record->value : $default;
        });
    }

    /**
     * Set a setting value and refresh cache.
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::put("setting.{$key}", $value, 300);
    }

    /**
     * Get all settings as key-value array.
     */
    public static function allAsArray(): array
    {
        return static::all()->pluck('value', 'key')->toArray();
    }
}
