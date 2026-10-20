<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\MembershipEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MembershipEvent> */
class MembershipEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory(),
            'event_key' => fn (array $attributes) => 'activated:'.$attributes['membership_id'],
            'type' => 'ACTIVATED', 'effective_at' => '2026-01-01 04:00:00',
            'recorded_at' => '2026-01-01 04:00:00', 'metadata' => null,
        ];
    }
}
