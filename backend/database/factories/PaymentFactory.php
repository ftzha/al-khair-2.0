<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AuthService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->state(['total' => '70.00']),
            'method' => 'CASH', 'status' => 'PENDING', 'amount' => '70.00',
            'submitted_by' => fn () => app(AuthService::class)->register([
                'full_name' => 'Test cashier', 'identity_number' => 'TEST-'.Str::uuid(),
                'email' => fake()->unique()->safeEmail(), 'password' => 'Password123!',
            ])->id,
            'submitted_at' => '2026-10-20 00:00:00',
        ];
    }
}
