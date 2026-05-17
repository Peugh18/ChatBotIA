<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'category_id', 'name', 'sku', 'description',
        'price', 'image_url', 'ai_tags', 'sales_count', 'discount_percent'
    ];

    protected $casts = [
        'ai_tags'          => 'array',
        'price'            => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'sales_count'      => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
}
