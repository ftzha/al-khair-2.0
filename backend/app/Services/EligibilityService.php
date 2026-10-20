<?php

namespace App\Services;

use App\Models\Membership;
use Carbon\CarbonImmutable;

class EligibilityService
{
    /** @return array{eligible: bool, reason: string, as_of: string} */
    public function asOf(Membership $membership, CarbonImmutable $instant): array
    {
        $utc = $instant->utc();
        $date = $instant->setTimezone('Asia/Kuala_Lumpur')->toDateString();
        $result = fn (bool $eligible, string $reason): array => [
            'eligible' => $eligible, 'reason' => $reason, 'as_of' => $utc->toISOString(),
        ];

        $state = $membership->events()
            ->reorder()->where('effective_at', '<=', $utc)
            ->whereIn('type', ['ACTIVATED', 'RENEWED', 'LAPSED', 'CLOSED'])
            ->orderByDesc('effective_at')->orderByDesc('id')->first();
        if ($state === null || $membership->activated_at->gt($utc)) {
            return $result(false, 'NOT_ACTIVATED');
        }
        if (in_array($state->type, ['LAPSED', 'CLOSED'], true)) {
            return $result(false, $state->type);
        }

        $covered = $membership->periods()
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
            ->where('effective_at', '<=', $utc)
            ->whereHas('invoice', fn ($query) => $query->where('status', 'PAID'))
            ->whereHas('payment', fn ($query) => $query->where('status', 'VERIFIED')->where('verified_at', '<=', $utc))
            ->exists();
        if (! $covered) {
            return $result(false, 'NO_PAID_PERIOD');
        }
        if ($date < $membership->waiting_until->toDateString()) {
            return $result(false, 'WAITING_PERIOD');
        }

        return $result(true, 'ELIGIBLE');
    }
}
