<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\MembershipPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MembershipPeriod> */
class MembershipPeriodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory(),
            'invoice_id' => fn (array $attributes) => Membership::findOrFail($attributes['membership_id'])->initial_invoice_id,
            'payment_id' => fn (array $attributes) => Membership::findOrFail($attributes['membership_id'])->activation_payment_id,
            'billing_year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
            'effective_at' => '2026-01-01 04:00:00',
        ];
    }
}
