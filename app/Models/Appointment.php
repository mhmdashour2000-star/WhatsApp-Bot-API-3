<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Appointment extends Model
{
    use HasFactory;

    protected static function booted()
    {
        static::creating(function (self $appointment) {
            if (empty($appointment->reference_code)) {
                $appointment->reference_code = Str::upper(Str::random(8));
            }
            // Set default appointment_date to today if not provided
            if (empty($appointment->appointment_date)) {
                $appointment->appointment_date = now()->toDateString();
            }
        });
    }

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
