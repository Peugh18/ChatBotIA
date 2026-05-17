<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickReply extends Model
{
    protected $fillable = ['shortcut', 'title', 'body', 'usage_count'];

    protected $casts = [
        'usage_count' => 'integer',
    ];
}
