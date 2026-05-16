<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'client_id', 'status', 'total', 'shipping_address', 
        'shipping_method', 'payment_receipt_url', 'tracking_number'
    ];

    protected $casts = [
        'total' => 'decimal:2'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
