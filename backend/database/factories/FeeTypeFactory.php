<?php

namespace Database\Factories;

use App\Models\FeeType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeeType> */
class FeeTypeFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('TEST_????####'), 'name' => 'Test fee'];
    }
}
