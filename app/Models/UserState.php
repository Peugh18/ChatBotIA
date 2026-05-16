<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserState extends Model
{
    protected $fillable = [
        'phone',
        'state',
    ];

    protected $casts = [
        'state' => 'array',
    ];
}
