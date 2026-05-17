<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'phone', 'name', 'status', 'priority', 'assigned_user_id',
        'last_interaction_at', 'first_response_at', 'confirmation_requested_at',
        'followup_3_sent_at', 'followup_15_sent_at', 'followup_stopped_at',
        'lead_score', 'lifetime_value',
        'state', 'budget_estimate', 'preferences',
    ];

    protected $casts = [
        'state'                      => 'array',
        'preferences'                => 'array',
        'last_interaction_at'        => 'datetime',
        'first_response_at'          => 'datetime',
        'confirmation_requested_at'  => 'datetime',
        'followup_3_sent_at'         => 'datetime',
        'followup_15_sent_at'        => 'datetime',
        'followup_stopped_at'        => 'datetime',
        'budget_estimate'            => 'decimal:2',
        'lifetime_value'             => 'decimal:2',
        'lead_score'                 => 'integer',
    ];

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'client_tag');
    }

    public function notes()
    {
        return $this->hasMany(ClientNote::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
