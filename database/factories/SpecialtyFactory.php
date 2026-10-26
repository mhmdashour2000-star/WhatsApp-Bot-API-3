<?php

namespace Database\Factories;

use App\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends \Illuminate\Database\Eloquent\Factories\Factory<Specialty> */
class SpecialtyFactory extends Factory
{
    protected $model = Specialty::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word,
            'code' => strtoupper(Str::random(5)),
            'description' => $this->faker->sentence,
            'is_active' => true,
        ];
    }
}
