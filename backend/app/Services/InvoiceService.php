<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Registration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(private readonly FeeRateService $fees) {}

    public function createInitial(Registration $registration): Invoice
    {
        return DB::transaction(function () use ($registration) {
            $registration = Registration::query()->lockForUpdate()->findOrFail($registration->id);
            if ($registration->status !== 'APPROVED' || $registration->approved_submission_id === null) {
                throw ValidationException::withMessages(['registration' => ['An approved application is required before invoicing.']]);
            }

            $billingKey = 'initial:'.$registration->id;
            $existing = Invoice::query()->where('billing_key', $billingKey)->first();
            if ($existing !== null) {
                return $existing->load('items');
            }

            $issued = CarbonImmutable::now('Asia/Kuala_Lumpur');
            $rates = [
                $this->fees->applicable('REGISTRATION', $issued->toDateString()),
                $this->fees->applicable('ANNUAL', $issued->toDateString()),
            ];
            $totalCents = array_sum(array_map(fn ($rate) => $this->fees->toCents($rate->amount), $rates));
            if ($totalCents > 999999999999) {
                throw ValidationException::withMessages(['fees' => ['The invoice total exceeds the supported amount.']]);
            }

            $invoice = Invoice::create([
                'invoice_number' => 'AK-'.Str::ulid(), 'billing_key' => $billingKey,
                'person_id' => $registration->person_id, 'registration_id' => $registration->id,
                'member_id' => null, 'type' => 'INITIAL', 'status' => 'UNPAID',
                'billing_year' => $issued->year, 'currency' => 'MYR',
                'total' => intdiv($totalCents, 100).'.'.str_pad((string) ($totalCents % 100), 2, '0', STR_PAD_LEFT),
                'issued_at' => $issued->utc(), 'due_date' => $issued->addDays(30)->toDateString(),
            ]);

            foreach ($rates as $rate) {
                $invoice->items()->create([
                    'fee_rate_id' => $rate->id, 'fee_code' => $rate->feeType->code,
                    'description' => $rate->feeType->name, 'amount' => $rate->amount,
                ]);
            }

            return $invoice->load('items');
        });
    }
}
