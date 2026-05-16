<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'client_id', 'from_me', 'body', 'type', 
        'media_url', 'meta_message_id'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
