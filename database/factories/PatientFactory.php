<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'primary_phone' => Str::random(10),
            'full_name' => $this->faker->name,
            'id_number' => $this->faker->unique()->numerify('##########'),
            'birth_date' => $this->faker->date('Y-m-d'),
            'gender' => $this->faker->randomElement(['male', 'female']),
            'is_blocked' => false,
            'blocked_reason' => null,
            'kvkk_consent_accepted' => true,
            'kvkk_consent_date' => now(),
            'notes' => $this->faker->sentence,
        ];
    }
}
