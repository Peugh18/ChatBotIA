<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    protected $table = 'pedidos';
    
    protected $fillable = [
        'phone',
        'name',
        'cellphone',
        'address',
        'product_name',
        'product_price',
        'quantity',
        'total',
    ];
}
