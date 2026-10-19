<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappSession extends Model
{
    protected $fillable = [
        'phone_number',
        'current_state',
        'state_payload',
        'last_interaction_at',
    ];

    protected $casts = [
        'state_payload' => 'array',
        'last_interaction_at' => 'datetime',
    ];
}
