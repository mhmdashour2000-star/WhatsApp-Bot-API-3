<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends \Illuminate\Database\Eloquent\Factories\Factory<Doctor> */
class DoctorFactory extends Factory
{
    protected $model = Doctor::class;

    public function definition(): array
    {
        return [
            'specialty_id' => Specialty::factory(),
            'full_name' => $this->faker->name,
            'title' => $this->faker->jobTitle,
            'phone' => $this->faker->phoneNumber,
            'email' => $this->faker->unique()->safeEmail,
            'slot_duration_minutes' => 30,
            'is_active' => true,
        ];
    }
}
