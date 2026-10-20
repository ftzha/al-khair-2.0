<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Membership> */
class MembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'initial_invoice_id' => Invoice::factory()->state(['type' => 'INITIAL', 'status' => 'PAID', 'total' => '70.00']),
            'member_id' => function (array $attributes) {
                $invoice = Invoice::findOrFail($attributes['initial_invoice_id']);
                $member = Member::firstOrCreate(['person_id' => $invoice->person_id], ['member_number' => 'AKM-'.Str::ulid()]);
                $invoice->update(['member_id' => $member->id]);

                return $member->id;
            },
            'activation_payment_id' => fn (array $attributes) => Payment::factory()->create([
                'invoice_id' => $attributes['initial_invoice_id'], 'status' => 'VERIFIED',
                'reviewed_at' => '2026-01-01 04:00:00', 'verified_at' => '2026-01-01 04:00:00',
                'submitted_at' => '2026-01-01 03:00:00',
            ])->id,
            'status' => 'ACTIVE', 'activated_at' => '2026-01-01 04:00:00',
            'activated_on' => '2026-01-01', 'waiting_until' => '2026-04-01',
        ];
    }
}
