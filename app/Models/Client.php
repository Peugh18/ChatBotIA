<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'phone', 'name', 'status', 'priority', 
        'last_interaction_at', 'state'
    ];

    protected $casts = [
        'state' => 'array',
        'last_interaction_at' => 'datetime'
    ];

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
