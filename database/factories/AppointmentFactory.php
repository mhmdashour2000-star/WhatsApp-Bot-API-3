<?php

namespace Database\Factories;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends \Illuminate\Database\Eloquent\Factories\Factory<Appointment> */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        return [
            'reference_code' => strtoupper(Str::random(10)),
            'patient_id' => null, // to be set in test via ->create([...])
            'doctor_id' => null,
            'appointment_date' => now()->addDays(1)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '09:30',
            'status' => 'holding',
            'holding_expires_at' => now()->addMinutes(15),
            'attended' => false,
            'version' => 1,
            'source' => 'whatsapp',
        ];
    }
}
