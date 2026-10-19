<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    protected $fillable = [
        'primary_phone',
        'full_name',
        'id_number',
        'birth_date',
        'gender',
        'is_blocked',
        'blocked_reason',
        'kvkk_consent_accepted',
        'kvkk_consent_date',
        'notes',
    ];

    protected $casts = [
        'is_blocked' => 'boolean',
        'kvkk_consent_accepted' => 'boolean',
        'kvkk_consent_date' => 'datetime',
        'birth_date' => 'date',
    ];

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
