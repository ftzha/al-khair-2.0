<?php

namespace Database\Factories;

use App\Models\FeeRate;
use App\Models\FeeType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeeRate> */
class FeeRateFactory extends Factory
{
    public function definition(): array
    {
        return ['fee_type_id' => FeeType::factory(), 'amount' => '20.00', 'currency' => 'MYR', 'effective_from' => '2026-01-01', 'effective_until' => null];
    }
}
