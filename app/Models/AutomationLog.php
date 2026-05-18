<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationLog extends Model
{
    protected $fillable = [
        'client_id',
        'type',
        'status',
        'message',
        'reason',
        'context',
        'sent_at',
    ];

    protected $casts = [
        'context' => 'array',
        'sent_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
