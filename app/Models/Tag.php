<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = ['name', 'color'];

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'client_tag');
    }
}
