<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    protected $fillable = [
        'reference_code',
        'patient_id',
        'doctor_id',
        'appointment_date',
        'start_time',
        'end_time',
        'status',
        'holding_expires_at',
        'cancellation_reason',
        'cancelled_at',
        'attended',
        'version',
        'source',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'holding_expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attended' => 'boolean',
        'version' => 'integer',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
