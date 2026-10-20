<?php

namespace App\Services;

use App\Models\FeeRate;
use App\Models\FeeType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeeRateService
{
    public function createRate(FeeType $type, string $amount, string $from, ?string $until = null): FeeRate
    {
        $this->toCents($amount);
        $from = $this->calendarDate($from);
        $until = $until === null ? null : $this->calendarDate($until);

        if ($until !== null && $until < $from) {
            throw ValidationException::withMessages(['effective_until' => ['The end date must not precede the start date.']]);
        }

        return DB::transaction(function () use ($type, $amount, $from, $until) {
            FeeType::query()->lockForUpdate()->findOrFail($type->id);
            $overlap = FeeRate::query()->where('fee_type_id', $type->id)
                ->when($until !== null, fn ($query) => $query->whereDate('effective_from', '<=', $until))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from))
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages(['fees' => ['Fee rate dates must not overlap. End dates are inclusive.']]);
            }

            return $type->rates()->create(['amount' => $amount, 'currency' => 'MYR', 'effective_from' => $from, 'effective_until' => $until]);
        });
    }

    public function applicable(string $code, string $date): FeeRate
    {
        $date = $this->calendarDate($date);
        $type = FeeType::query()->where('code', $code)->lockForUpdate()->first();
        $rates = $type === null ? collect() : $type->rates()->where('currency', 'MYR')
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->get();

        if ($rates->count() !== 1) {
            throw ValidationException::withMessages(['fees' => ["Exactly one {$code} fee rate must apply on {$date}."]]);
        }

        $this->toCents($rates->first()->amount);

        return $rates->first()->setRelation('feeType', $type);
    }

    public function toCents(string $amount): int
    {
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => ['Enter a nonnegative MYR amount with at most two decimal places.']]);
        }

        $parts = explode('.', $amount);

        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function calendarDate(string $date): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages(['date' => ['Use a valid YYYY-MM-DD calendar date.']]);
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Exception) {
            throw ValidationException::withMessages(['date' => ['Use a valid YYYY-MM-DD calendar date.']]);
        }
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => ['Use a valid YYYY-MM-DD calendar date.']]);
        }

        return $date;
    }
}
