<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Services\PersonService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_number' => 'TEST-'.Str::ulid(), 'billing_key' => 'test:'.Str::ulid(),
            'person_id' => fn () => app(PersonService::class)->create([
                'full_name' => 'Test invoice recipient', 'identity_number' => 'TEST-'.Str::uuid(),
            ])->id,
            'registration_id' => null, 'member_id' => null, 'type' => 'RENEWAL',
            'status' => 'UNPAID', 'billing_year' => 2026, 'currency' => 'MYR', 'total' => '0.00',
            'issued_at' => '2026-10-20 00:00:00', 'due_date' => '2026-11-19',
        ];
    }
}
