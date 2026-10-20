<?php

namespace Database\Factories;

use App\Models\FeeRate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceItem> */
class InvoiceItemFactory extends Factory
{
    public function definition(): array
    {
        return ['invoice_id' => Invoice::factory(), 'fee_rate_id' => FeeRate::factory(), 'fee_code' => 'REGISTRATION', 'description' => 'Registration fee', 'amount' => '20.00'];
    }
}
