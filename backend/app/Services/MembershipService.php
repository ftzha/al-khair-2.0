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
