<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'phone', 'name', 'status', 'priority', 'assigned_user_id',
        'bot_paused_at',
        'last_interaction_at', 'last_customer_message_at', 'first_response_at', 'confirmation_requested_at',
        'followup_3_sent_at', 'followup_15_sent_at', 'followup_stopped_at', 'last_followup_at',
        'followup_count', 'opted_out_at',
        'lead_score', 'lifetime_value',
        'state', 'budget_estimate', 'preferences',
        'payment_receipt_url', 'paid_amount', 'payment_verified_by', 'payment_verified_at',
    ];

    protected $casts = [
        'state'                      => 'array',
        'preferences'                => 'array',
        'bot_paused_at'              => 'datetime',
        'last_interaction_at'        => 'datetime',
        'last_customer_message_at'   => 'datetime',
        'first_response_at'          => 'datetime',
        'confirmation_requested_at'  => 'datetime',
        'followup_3_sent_at'         => 'datetime',
        'followup_15_sent_at'        => 'datetime',
        'followup_stopped_at'        => 'datetime',
        'last_followup_at'           => 'datetime',
        'opted_out_at'               => 'datetime',
        'followup_count'             => 'integer',
        'budget_estimate'            => 'decimal:2',
        'lifetime_value'             => 'decimal:2',
        'lead_score'                 => 'integer',
        'paid_amount'                => 'decimal:2',
        'payment_verified_at'        => 'datetime',
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

    public function canReceiveFreeformWhatsApp(): bool
    {
        return $this->last_customer_message_at !== null
            && $this->last_customer_message_at->gte(now()->subHours(24));
    }

    public function canReceiveAutomation(int $maxFollowUps = 3): bool
    {
        if ($this->opted_out_at !== null) {
            return false;
        }

        if (($this->followup_count ?? 0) >= $maxFollowUps) {
            return false;
        }

        return $this->canReceiveFreeformWhatsApp();
    }
}
