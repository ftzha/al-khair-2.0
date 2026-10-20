<?php

namespace Tests\Feature;

use App\Models\FeeRate;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\MembershipEvent;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuthService;
use App\Services\EligibilityService;
use App\Services\InvoiceService;
use App\Services\MembershipService;
use App\Services\PaymentService;
use App\Services\RegistrationReviewService;
use App\Services\RegistrationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoFeeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MembershipRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    private function account(string $label, string $role = 'MEMBER'): User
    {
        $user = app(AuthService::class)->register([
            'full_name' => 'Test '.$label, 'identity_number' => 'TEST-'.$label,
            'email' => $label.'@alkhair.test', 'password' => 'Password123!',
            'date_of_birth' => '1990-01-01',
        ]);
        $user->update(['role' => $role]);
        $user->markEmailAsVerified();

        return $user;
    }

    /** @return array{Membership, User, User, User} */
    private function activated(string $issued = '2026-01-01T04:00:00Z', ?string $verified = null): array
    {
        $this->travelTo(CarbonImmutable::parse($issued));
        $this->seed(DemoFeeSeeder::class);
        $owner = $this->account('owner');
        $cashier = $this->account('cashier', 'COMMITTEE');
        $reviewer = $this->account('reviewer', 'ADMIN');
        $registration = app(RegistrationService::class)->createDraft($owner);
        app(RegistrationService::class)->submit($registration, $owner);
        app(RegistrationReviewService::class)->review($registration, $reviewer, 'APPROVE');
        $invoice = Invoice::where('registration_id', $registration->id)->sole();
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '70.00');
        $this->travelTo(CarbonImmutable::parse($verified ?? $issued));
        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');

        return [Membership::sole(), $owner, $cashier, $reviewer];
    }

    /** @return array{Membership, Invoice, User, User, User} */
    private function renewal(): array
    {
        [$membership, $owner, $cashier, $reviewer] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2027-01-01T04:00:00Z'));
        $invoice = app(InvoiceService::class)->createRenewal($membership);

        return [$membership, $invoice, $owner, $cashier, $reviewer];
    }

    public function test_generation_is_annual_only_repeatable_and_preserves_issued_amounts(): void
    {
        [$membership] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2026-12-31T16:00:00Z'));

        $this->artisan('memberships:generate-renewals')->assertSuccessful();
        $this->artisan('memberships:generate-renewals')->assertSuccessful();

        $invoice = Invoice::where('type', 'RENEWAL')->with('items')->sole();
        $this->assertSame('50.00', $invoice->total);
        $this->assertSame(2027, $invoice->billing_year);
        $this->assertSame('2027-01-30', $invoice->due_date->toDateString());
        $this->assertSame($membership->member_id, $invoice->member_id);
        $this->assertSame($membership->member->person_id, $invoice->person_id);
        $this->assertSame('UNPAID', $invoice->status);
        $this->assertNull($invoice->registration_id);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('ANNUAL', $invoice->items->sole()->fee_code);
        FeeRate::whereHas('feeType', fn ($query) => $query->where('code', 'ANNUAL'))->update(['amount' => '65.00']);

        $again = app(InvoiceService::class)->createRenewal($membership);
        $this->assertSame($invoice->id, $again->id);
        $this->assertSame('50.00', $again->items->sole()->amount);
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseCount('membership_periods', 1);
    }

    public function test_initial_year_is_not_charged_a_second_annual_fee(): void
    {
        [$membership] = $this->activated();

        $this->artisan('memberships:generate-renewals')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(0, Invoice::where('type', 'RENEWAL')->count());
        try {
            app(InvoiceService::class)->createRenewal($membership);
            $this->fail('Expected the already-paid initial year to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('renewal', $exception->errors());
        }
        $this->assertDatabaseCount('invoice_items', 2);
    }

    public function test_new_renewal_uses_the_rate_effective_on_issuance(): void
    {
        [$membership] = $this->activated();
        FeeRate::whereHas('feeType', fn ($query) => $query->where('code', 'ANNUAL'))
            ->update(['effective_until' => '2026-12-31']);
        $type = \App\Models\FeeType::where('code', 'ANNUAL')->sole();
        app(\App\Services\FeeRateService::class)->createRate($type, '60.00', '2027-01-01');
        $this->travelTo(CarbonImmutable::parse('2027-01-02T04:00:00Z'));

        $invoice = app(InvoiceService::class)->createRenewal($membership);

        $this->assertSame('60.00', $invoice->total);
        $this->assertSame('60.00', $invoice->items->sole()->amount);
        $this->assertSame('70.00', $membership->initialInvoice->total);
    }

    public function test_missing_annual_rate_reports_failure_without_creating_an_invoice(): void
    {
        [$membership] = $this->activated();
        FeeRate::whereHas('feeType', fn ($query) => $query->where('code', 'ANNUAL'))
            ->update(['effective_until' => '2026-12-31']);
        $this->travelTo(CarbonImmutable::parse('2027-01-01T04:00:00Z'));

        $this->artisan('memberships:generate-renewals')->assertFailed();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertSame('ACTIVE', $membership->fresh()->status);
    }

    public function test_item_failure_rolls_back_the_new_renewal_invoice(): void
    {
        [$membership] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2027-01-01T04:00:00Z'));
        \App\Models\InvoiceItem::creating(fn () => throw new \RuntimeException('Simulated renewal item failure.'));

        try {
            app(InvoiceService::class)->createRenewal($membership);
            $this->fail('Expected invoice item failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated renewal item failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 2);
    }

    public static function methods(): array
    {
        return ['cash' => ['CASH'], 'bank transfer' => ['BANK_TRANSFER']];
    }

    #[DataProvider('methods')]
    public function test_last_second_timely_payment_can_be_verified_after_cutoff(string $method): void
    {
        [$membership, $invoice, $owner, $cashier, $reviewer] = $this->renewal();
        $waitingUntil = $membership->waiting_until->toDateString();
        $this->travelTo(CarbonImmutable::parse('2027-01-30T15:59:59Z'));
        $service = app(PaymentService::class);
        if ($method === 'CASH') {
            $payment = $service->recordCash($invoice, $cashier, '50.00');
        } else {
            Storage::fake('local');
            $payment = $service->submitBankTransfer($invoice, $owner, '50.00', 'RENEWAL-TEST',
                UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'));
        }
        $this->assertSame('2027-01-30 15:59:59', $payment->submitted_at->format('Y-m-d H:i:s'));
        $this->assertFalse(app(EligibilityService::class)->asOf($membership, CarbonImmutable::now())['eligible']);
        $this->travelTo(CarbonImmutable::parse('2027-01-30T16:00:00Z'));
        $this->artisan('memberships:sync-statuses')->assertSuccessful();
        $this->artisan('memberships:sync-statuses')->assertSuccessful();
        $this->assertSame('LAPSED', $membership->fresh()->status);
        $this->travelTo(CarbonImmutable::parse('2027-02-10T04:00:00Z'));
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'VERIFY'])->assertOk();

        $membership->refresh();
        $this->assertSame('ACTIVE', $membership->status);
        $this->assertSame($waitingUntil, $membership->waiting_until->toDateString());
        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertSame('VERIFIED', $payment->fresh()->status);
        $eligibility = app(EligibilityService::class);
        $this->assertFalse($eligibility->asOf($membership, CarbonImmutable::parse('2027-02-10T03:59:59Z'))['eligible']);
        $this->assertTrue($eligibility->asOf($membership, CarbonImmutable::parse('2027-02-10T04:00:00Z'))['eligible']);
        $this->assertFalse($eligibility->asOf($membership, CarbonImmutable::parse('2027-01-15T04:00:00Z'))['eligible']);
        $period = $membership->periods()->where('billing_year', 2027)->sole();
        $this->assertSame('2027-01-01', $period->starts_on->toDateString());
        $this->assertSame('2027-12-31', $period->ends_on->toDateString());
        $this->assertSame('2027-02-10 04:00:00', $period->effective_at->format('Y-m-d H:i:s'));
        $this->assertSame($membership->id, app(MembershipService::class)->renewVerifiedPayment($payment)->id);
        $this->assertDatabaseCount('membership_periods', 2);
        $this->assertSame(1, $membership->events()->where('type', 'RENEWED')->count());
        $this->assertSame(1, $membership->events()->where('type', 'LAPSED')->count());
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('memberships', 1);
    }

    #[DataProvider('methods')]
    public function test_january_31_submission_is_rejected_for_both_payment_methods(string $method): void
    {
        [, $invoice, $owner, $cashier] = $this->renewal();
        $this->travelTo(CarbonImmutable::parse('2027-01-30T16:00:00Z'));
        Storage::fake('local');
        if ($method === 'CASH') {
            Sanctum::actingAs($cashier);
            $response = $this->postJson("/api/committee/payment-invoices/{$invoice->id}/cash", ['amount' => '50.00']);
        } else {
            Sanctum::actingAs($owner);
            $response = $this->postJson("/api/invoices/{$invoice->id}/payments", [
                'amount' => '50.00', 'reference' => 'LATE-TEST',
                'receipt' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            ]);
        }

        $response->assertUnprocessable()->assertJsonValidationErrors('renewal');
        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame([], Storage::disk('local')->allFiles('receipts'));
    }

    public function test_rejected_timely_payment_cannot_be_replaced_after_cutoff(): void
    {
        [$membership, $invoice, , $cashier, $reviewer] = $this->renewal();
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '50.00');
        $this->travelTo(CarbonImmutable::parse('2027-02-01T04:00:00Z'));
        app(PaymentService::class)->review($payment, $reviewer, 'REJECT', 'Cash not received.');
        Sanctum::actingAs($cashier);

        $this->postJson("/api/committee/payment-invoices/{$invoice->id}/cash", ['amount' => '50.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('renewal');

        $this->assertSame('REJECTED', $payment->fresh()->status);
        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertSame(1, $membership->periods()->count());
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_verification_rechecks_timeliness_even_for_an_imported_late_payment(): void
    {
        [$membership, $invoice, , $cashier, $reviewer] = $this->renewal();
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id, 'amount' => '50.00', 'submitted_by' => $cashier->id,
            'submitted_at' => '2027-01-30 16:00:00',
        ]);
        $this->travelTo(CarbonImmutable::parse('2027-02-01T04:00:00Z'));
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'VERIFY'])
            ->assertUnprocessable()->assertJsonValidationErrors('renewal');

        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->verified_at);
        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertSame(1, $membership->periods()->count());
    }

    public function test_grace_period_is_ineligible_and_lapse_is_recorded_at_malaysia_midnight(): void
    {
        [$membership] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2027-01-30T15:59:59Z'));
        $this->artisan('memberships:sync-statuses')->assertSuccessful();
        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertFalse(app(EligibilityService::class)->asOf($membership, CarbonImmutable::now())['eligible']);

        $this->travelTo(CarbonImmutable::parse('2027-01-30T16:00:00Z'));
        $this->artisan('memberships:sync-statuses')->assertSuccessful();

        $this->assertSame('LAPSED', $membership->fresh()->status);
        $event = $membership->events()->where('type', 'LAPSED')->sole();
        $this->assertSame('2027-01-30 16:00:00', $event->effective_at->format('Y-m-d H:i:s'));
        $this->assertTrue(app(EligibilityService::class)->asOf($membership, CarbonImmutable::parse('2026-12-31T15:59:59Z'))['eligible']);
    }

    public function test_on_time_verification_adds_a_period_without_lapse_or_new_wait(): void
    {
        [$membership, $invoice, , $cashier, $reviewer] = $this->renewal();
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '50.00');

        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');
        $this->travelTo(CarbonImmutable::parse('2027-02-01T04:00:00Z'));
        $this->artisan('memberships:sync-statuses')->assertSuccessful();

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertSame('2026-04-01', $membership->waiting_until->toDateString());
        $this->assertTrue(app(EligibilityService::class)->asOf($membership, CarbonImmutable::now())['eligible']);
        $this->assertSame(0, $membership->events()->where('type', 'LAPSED')->count());
    }

    public function test_renewal_event_failure_rolls_back_verification_period_and_lapse(): void
    {
        [$membership, $invoice, , $cashier, $reviewer] = $this->renewal();
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '50.00');
        $this->travelTo(CarbonImmutable::parse('2027-02-01T04:00:00Z'));
        MembershipEvent::creating(function (MembershipEvent $event): void {
            if ($event->type === 'RENEWED') {
                throw new \RuntimeException('Simulated renewal event failure.');
            }
        });

        try {
            app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');
            $this->fail('Expected renewal failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated renewal event failure.', $exception->getMessage());
        }

        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->verified_at);
        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertDatabaseCount('membership_periods', 1);
        $this->assertDatabaseCount('membership_events', 1);
    }

    public function test_january_activation_from_december_invoice_can_renew_without_registration_fee(): void
    {
        [$membership, , $cashier, $reviewer] = $this->activated('2026-12-31T12:00:00Z', '2027-01-01T04:00:00Z');
        $invoice = app(InvoiceService::class)->createRenewal($membership);
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '50.00');

        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');

        $membership->refresh();
        $this->assertSame('2027-04-01', $membership->waiting_until->toDateString());
        $this->assertSame(['ANNUAL'], $invoice->items->pluck('fee_code')->all());
        $this->assertFalse(app(EligibilityService::class)->asOf($membership, CarbonImmutable::now())['eligible']);
        $this->assertTrue(app(EligibilityService::class)->asOf($membership, CarbonImmutable::parse('2027-03-31T16:00:00Z'))['eligible']);
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseCount('membership_periods', 2);
    }

    public function test_no_renewal_is_generated_outside_grace_or_for_a_gap_year(): void
    {
        [$membership] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2027-02-01T04:00:00Z'));
        $this->artisan('memberships:generate-renewals')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2028-01-01T04:00:00Z'));
        $this->artisan('memberships:generate-renewals')->assertSuccessful();

        try {
            app(InvoiceService::class)->createRenewal($membership);
            $this->fail('Expected renewal across an unpaid gap to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('renewal', $exception->errors());
        }

        $this->assertDatabaseCount('invoices', 1);
        $this->artisan('memberships:sync-statuses')->assertSuccessful();
        $this->assertSame('LAPSED', $membership->fresh()->status);
        $this->assertSame('2027-01-30 16:00:00', $membership->events()->where('type', 'LAPSED')->sole()->effective_at->format('Y-m-d H:i:s'));
    }

    public function test_late_verification_records_missing_lapse_when_scheduler_has_not_run(): void
    {
        [$membership, $invoice, , $cashier, $reviewer] = $this->renewal();
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '50.00');
        $this->travelTo(CarbonImmutable::parse('2027-02-01T04:00:00Z'));

        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $lapse = $membership->events()->where('type', 'LAPSED')->sole();
        $this->assertSame('2027-01-30 16:00:00', $lapse->effective_at->format('Y-m-d H:i:s'));
        $this->assertFalse(app(EligibilityService::class)->asOf($membership, CarbonImmutable::parse('2027-01-31T04:00:00Z'))['eligible']);
        $this->assertTrue(app(EligibilityService::class)->asOf($membership, CarbonImmutable::now())['eligible']);
    }

    public function test_late_verification_for_a_past_year_does_not_cover_the_current_year(): void
    {
        [$membership, $invoice, , $cashier, $reviewer] = $this->renewal();
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '50.00');
        $this->travelTo(CarbonImmutable::parse('2028-02-01T04:00:00Z'));

        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');

        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertSame('LAPSED', $membership->fresh()->status);
        $this->assertFalse(app(EligibilityService::class)->asOf($membership, CarbonImmutable::now())['eligible']);
        $this->assertSame([2026, 2027], $membership->periods()->pluck('billing_year')->all());
        $this->assertSame(2, $membership->events()->where('type', 'LAPSED')->count());
    }

    public function test_closed_membership_cannot_be_renewed_by_pending_payment(): void
    {
        [$membership, $invoice, , $cashier, $reviewer] = $this->renewal();
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '50.00');
        $membership->update(['status' => 'CLOSED']);
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'VERIFY'])->assertUnprocessable();

        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertSame('CLOSED', $membership->fresh()->status);
        $this->assertDatabaseCount('membership_periods', 1);
    }
}
