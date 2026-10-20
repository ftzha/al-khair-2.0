<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Person;
use App\Models\Registration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipService
{
    private const INITIAL_WAITING_DAYS = 90;

    public function __construct(private readonly FeeRateService $money) {}

    public function assertRenewalPayable(Invoice $invoice, CarbonImmutable $submittedAt): void
    {
        if ($invoice->type !== 'RENEWAL') {
            return;
        }
        $now = $submittedAt->setTimezone('Asia/Kuala_Lumpur');
        if ($invoice->billing_year !== $now->year || $now->month !== 1 || $now->day > 30) {
            throw ValidationException::withMessages(['renewal' => ['Renewal payments must be submitted or cash-recorded by January 30 of the billing year, Malaysia time.']]);
        }
        $membership = $this->renewalMembership($invoice);
        if ($membership->status !== 'ACTIVE'
            || $membership->periods()->where('billing_year', $invoice->billing_year)->exists()) {
            throw ValidationException::withMessages(['renewal' => ['This membership cannot accept a new renewal payment.']]);
        }
    }

    private function renewalMembership(Invoice $invoice): Membership
    {
        $membership = Membership::query()->where('member_id', $invoice->member_id)->lockForUpdate()->first();
        if ($membership === null || $membership->status === 'CLOSED'
            || $membership->member->person_id !== $invoice->person_id
            || $invoice->currency !== 'MYR'
            || ! $membership->periods()->where('billing_year', $invoice->billing_year - 1)
                ->where('effective_at', '<=', now())
                ->whereHas('invoice', fn ($query) => $query->where('status', 'PAID'))
                ->whereHas('payment', fn ($query) => $query->where('status', 'VERIFIED'))->exists()) {
            throw ValidationException::withMessages(['renewal' => ['A matching, non-closed membership with the previous year paid is required.']]);
        }

        return $membership;
    }

    public function renewVerifiedPayment(Payment $payment): Membership
    {
        return DB::transaction(function () use ($payment) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($invoice->type !== 'RENEWAL' || $invoice->status !== 'PAID'
                || $payment->invoice_id !== $invoice->id || $payment->status !== 'VERIFIED'
                || $payment->verified_at === null || $payment->reviewed_by === null
                || $payment->reviewed_by === $payment->submitted_by
                || $payment->reviewer?->person_id === $invoice->person_id
                || $this->money->toCents($payment->amount) !== $this->money->toCents($invoice->total)
                || $this->money->toCents($invoice->total) === 0) {
                throw ValidationException::withMessages(['renewal' => ['A full, independently verified renewal payment is required.']]);
            }
            $year = $invoice->billing_year;
            $cutoff = CarbonImmutable::create($year, 1, 31, 0, 0, 0, 'Asia/Kuala_Lumpur');
            $start = $cutoff->startOfYear();
            if ($payment->submitted_at->lt($start) || $payment->submitted_at->gte($cutoff)
                || $payment->verified_at->lt($payment->submitted_at)) {
                throw ValidationException::withMessages(['renewal' => ['Only payments submitted or cash-recorded during January 1–30 can renew membership.']]);
            }
            $membership = $this->renewalMembership($invoice);
            $existing = $membership->periods()->where('billing_year', $year)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->invoice_id !== $invoice->id || $existing->payment_id !== $payment->id) {
                    throw ValidationException::withMessages(['renewal' => ['This billing year already has a different paid period.']]);
                }

                return $membership->load('periods', 'events');
            }

            if ($payment->verified_at->gte($cutoff)) {
                $this->appendLapse($membership, $year);
            }
            $membership->periods()->create([
                'invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'billing_year' => $year,
                'starts_on' => $start->toDateString(), 'ends_on' => $start->endOfYear()->toDateString(),
                'effective_at' => $payment->verified_at->utc(),
            ]);
            $membership->events()->create([
                'event_key' => 'renewed:'.$membership->id.':'.$year, 'type' => 'RENEWED',
                'effective_at' => $payment->verified_at->utc(), 'recorded_at' => now(),
                'actor_id' => $payment->reviewed_by,
                'metadata' => ['billing_year' => $year, 'invoice_id' => $invoice->id, 'payment_id' => $payment->id],
            ]);
            $membership->update(['status' => 'ACTIVE']);
            $this->syncStatus($membership);

            return $membership->refresh()->load('periods', 'events');
        });
    }

    public function syncStatus(Membership $membership): Membership
    {
        return DB::transaction(function () use ($membership) {
            $membership = Membership::query()->lockForUpdate()->findOrFail($membership->id);
            if ($membership->status === 'CLOSED' || $membership->activated_at->isFuture()) {
                return $membership;
            }
            $lastPaid = $membership->periods()->reorder()
                ->where('effective_at', '<=', now())
                ->whereHas('invoice', fn ($query) => $query->where('status', 'PAID'))
                ->whereHas('payment', fn ($query) => $query->where('status', 'VERIFIED'))
                ->orderByDesc('billing_year')->first();
            if ($lastPaid === null) {
                return $membership;
            }
            $year = $lastPaid->billing_year + 1;
            $cutoff = CarbonImmutable::create($year, 1, 31, 0, 0, 0, 'Asia/Kuala_Lumpur');
            if (CarbonImmutable::now()->gte($cutoff)) {
                $this->appendLapse($membership, $year);
                if ($membership->status !== 'LAPSED') {
                    $membership->update(['status' => 'LAPSED']);
                }
            }

            return $membership;
        });
    }

    private function appendLapse(Membership $membership, int $year): void
    {
        $key = 'lapsed:'.$membership->id.':'.$year;
        if ($membership->events()->where('event_key', $key)->lockForUpdate()->first() !== null) {
            return;
        }
        $effective = CarbonImmutable::create($year, 1, 31, 0, 0, 0, 'Asia/Kuala_Lumpur')->utc();
        $previous = $membership->periods()->where('billing_year', $year - 1)->first();
        if ($previous !== null && $previous->effective_at->gt($effective)) {
            $effective = $previous->effective_at;
        }
        if ($membership->activated_at->gt($effective)) {
            $effective = $membership->activated_at;
        }
        $membership->events()->create([
            'event_key' => $key, 'type' => 'LAPSED', 'effective_at' => $effective,
            'recorded_at' => now(), 'actor_id' => null, 'metadata' => ['billing_year' => $year],
        ]);
    }

    public function activateInitialPayment(Payment $payment): Membership
    {
        return DB::transaction(function () use ($payment) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($invoice->type !== 'INITIAL' || $invoice->status !== 'PAID'
                || $payment->invoice_id !== $invoice->id || $payment->status !== 'VERIFIED'
                || $payment->verified_at === null || $payment->reviewed_by === null
                || $payment->reviewed_by === $payment->submitted_by
                || $payment->reviewer?->person_id === $invoice->person_id
                || $invoice->currency !== 'MYR'
                || $this->money->toCents($payment->amount) !== $this->money->toCents($invoice->total)
                || $this->money->toCents($invoice->total) === 0) {
                throw ValidationException::withMessages(['membership' => ['Activation requires a fully paid initial invoice with independently verified payment.']]);
            }

            // Serialize activation across different invoices for the same person.
            Person::query()->lockForUpdate()->findOrFail($invoice->person_id);
            $registration = Registration::query()->lockForUpdate()->find($invoice->registration_id);
            if ($registration === null || $registration->person_id !== $invoice->person_id
                || $registration->status !== 'APPROVED'
                || $registration->approved_submission_id === null
                || ! $registration->submissions()->whereKey($registration->approved_submission_id)->exists()) {
                throw ValidationException::withMessages(['membership' => ['An approved registration belonging to the invoice recipient is required.']]);
            }

            $existing = Membership::query()->where('initial_invoice_id', $invoice->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->activation_payment_id !== $payment->id
                    || $existing->member->person_id !== $invoice->person_id
                    || $invoice->member_id !== $existing->member_id) {
                    throw ValidationException::withMessages(['membership' => ['This initial invoice is already linked to a different activation.']]);
                }

                return $existing->load('periods', 'events');
            }

            $member = Member::query()->where('person_id', $invoice->person_id)->lockForUpdate()->first();
            if (($member !== null && $member->membership()->lockForUpdate()->first() !== null)
                || ($invoice->member_id !== null && $invoice->member_id !== $member?->id)) {
                throw ValidationException::withMessages(['membership' => ['This person already has a membership or the invoice has a conflicting member.']]);
            }
            $member ??= Member::create([
                'person_id' => $invoice->person_id, 'member_number' => 'AKM-'.Str::ulid(),
            ]);

            $activated = $payment->verified_at->setTimezone('Asia/Kuala_Lumpur');
            $day = $activated->startOfDay();
            $membership = Membership::create([
                'member_id' => $member->id, 'initial_invoice_id' => $invoice->id,
                'activation_payment_id' => $payment->id, 'status' => 'ACTIVE',
                'activated_at' => $payment->verified_at->utc(),
                'activated_on' => $day->toDateString(),
                'waiting_until' => $day->addDays(self::INITIAL_WAITING_DAYS)->toDateString(),
            ]);
            $year = $invoice->billing_year;
            $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'Asia/Kuala_Lumpur');
            $membership->periods()->create([
                'invoice_id' => $invoice->id, 'payment_id' => $payment->id,
                'billing_year' => $year, 'starts_on' => $start->toDateString(),
                'ends_on' => $start->endOfYear()->toDateString(),
                'effective_at' => $payment->verified_at->utc(),
            ]);
            $membership->events()->create([
                'event_key' => 'activated:'.$membership->id, 'type' => 'ACTIVATED',
                'effective_at' => $payment->verified_at->utc(), 'recorded_at' => now(),
                'actor_id' => $payment->reviewed_by,
                'metadata' => [
                    'invoice_id' => $invoice->id, 'payment_id' => $payment->id,
                    'registration_id' => $registration->id, 'billing_year' => $year,
                    'waiting_until' => $membership->waiting_until->toDateString(),
                ],
            ]);
            $invoice->update(['member_id' => $member->id]);

            return $membership->load('periods', 'events');
        });
    }
}
