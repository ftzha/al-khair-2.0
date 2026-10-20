<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipEvent;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\User;
use App\Services\AuthService;
use App\Services\EligibilityService;
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

class MembershipActivationTest extends TestCase
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

    /** @return array{Invoice, User, User} */
    private function initialInvoice(string $issuedAt = '2026-01-01T04:00:00Z'): array
    {
        $this->travelTo(CarbonImmutable::parse($issuedAt));
        $this->seed(DemoFeeSeeder::class);
        $owner = $this->account('owner');
        $reviewer = $this->account('reviewer', 'COMMITTEE');
        $registration = app(RegistrationService::class)->createDraft($owner);
        app(RegistrationService::class)->submit($registration, $owner);
        app(RegistrationReviewService::class)->review($registration, $reviewer, 'APPROVE');

        return [Invoice::where('registration_id', $registration->id)->sole(), $owner, $reviewer];
    }

    private function verify(Payment $payment, User $reviewer): void
    {
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'VERIFY'])
            ->assertOk()->assertJsonPath('data.status', 'VERIFIED');
    }

    public static function paymentMethods(): array
    {
        return ['cash' => ['CASH'], 'bank transfer' => ['BANK_TRANSFER']];
    }

    #[DataProvider('paymentMethods')]
    public function test_initial_verification_creates_one_membership_period_and_activation_event(string $method): void
    {
        [$invoice, $owner, $reviewer] = $this->initialInvoice();
        $service = app(PaymentService::class);
        if ($method === 'CASH') {
            $payment = $service->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        } else {
            Storage::fake('local');
            $payment = $service->submitBankTransfer($invoice, $owner, '70.00', 'TRANSFER-TEST', UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'));
        }
        $this->assertDatabaseCount('members', 0);
        $this->travelTo(CarbonImmutable::parse('2026-01-02T16:30:00Z'));

        $this->verify($payment, $reviewer);

        $membership = Membership::sole();
        $this->assertSame('2026-01-03', $membership->activated_on->toDateString());
        $this->assertSame('2026-04-03', $membership->waiting_until->toDateString());
        $this->assertSame('2026-01-02 16:30:00', $membership->activated_at->format('Y-m-d H:i:s'));
        $this->assertSame('ACTIVE', $membership->status);
        $this->assertSame($owner->person_id, $membership->member->person_id);
        $this->assertMatchesRegularExpression('/^AKM-[A-Z0-9]{26}$/', $membership->member->member_number);
        $this->assertSame($membership->member_id, $invoice->fresh()->member_id);
        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertDatabaseHas('membership_periods', [
            'membership_id' => $membership->id, 'invoice_id' => $invoice->id,
            'payment_id' => $payment->id, 'billing_year' => 2026,
            'effective_at' => '2026-01-02 16:30:00',
        ]);
        $period = $membership->periods()->sole();
        $this->assertSame('2026-01-01', $period->starts_on->toDateString());
        $this->assertSame('2026-12-31', $period->ends_on->toDateString());
        $event = MembershipEvent::sole();
        $this->assertSame('ACTIVATED', $event->type);
        $this->assertSame($reviewer->id, $event->actor_id);
        $this->assertSame($payment->id, $event->metadata['payment_id']);

        $retried = app(MembershipService::class)->activateInitialPayment($payment);
        $this->assertSame($membership->id, $retried->id);
        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'VERIFY'])->assertUnprocessable();
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('membership_periods', 1);
        $this->assertDatabaseCount('membership_events', 1);
    }

    public function test_rejected_payment_does_not_activate_membership(): void
    {
        [$invoice, , $reviewer] = $this->initialInvoice();
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'REJECT', 'reason' => 'Cash not received.'])->assertOk();

        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('membership_events', 0);
        $this->assertSame('UNPAID', $invoice->fresh()->status);
    }

    public static function invalidRegistrations(): array
    {
        return ['not approved' => ['status'], 'missing approved submission' => ['submission'], 'no registration' => ['missing'], 'different person' => ['owner']];
    }

    #[DataProvider('invalidRegistrations')]
    public function test_invalid_initial_registration_rolls_back_verification(string $invalid): void
    {
        [$invoice, , $reviewer] = $this->initialInvoice();
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        $registration = $invoice->registration;
        match ($invalid) {
            'status' => $registration->update(['status' => 'PENDING_REVIEW']),
            'submission' => $registration->forceFill(['approved_submission_id' => null])->save(),
            'missing' => $invoice->update(['registration_id' => null]),
            'owner' => $invoice->update(['person_id' => $this->account('different')->person_id]),
        };
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'VERIFY'])
            ->assertUnprocessable()->assertJsonValidationErrors('membership');

        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->verified_at);
        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('membership_periods', 0);
        $this->assertDatabaseCount('membership_events', 0);
    }

    public function test_event_write_failure_rolls_back_payment_invoice_member_and_period(): void
    {
        [$invoice, , $reviewer] = $this->initialInvoice();
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        MembershipEvent::creating(fn () => throw new \RuntimeException('Simulated membership event failure.'));

        try {
            app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');
            $this->fail('Expected activation failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated membership event failure.', $exception->getMessage());
        }

        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->verified_at);
        $this->assertNull($payment->fresh()->reviewed_by);
        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->member_id);
        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('membership_periods', 0);
        $this->assertDatabaseCount('membership_events', 0);
    }

    public static function waitingBoundaries(): array
    {
        return [
            'before activation' => ['2026-01-01T03:59:59Z', false, 'NOT_ACTIVATED'],
            'activation' => ['2026-01-01T04:00:00Z', false, 'WAITING_PERIOD'],
            'one second before day 90' => ['2026-03-31T15:59:59Z', false, 'WAITING_PERIOD'],
            'Malaysia midnight day 90' => ['2026-03-31T16:00:00Z', true, 'ELIGIBLE'],
            'during day 90' => ['2026-04-01T04:00:00Z', true, 'ELIGIBLE'],
            'last second of billing year' => ['2026-12-31T15:59:59Z', true, 'ELIGIBLE'],
            'new year without renewal' => ['2026-12-31T16:00:00Z', false, 'NO_PAID_PERIOD'],
        ];
    }

    #[DataProvider('waitingBoundaries')]
    public function test_eligibility_uses_malaysia_calendar_boundaries(string $asOf, bool $eligible, string $reason): void
    {
        [$invoice, , $reviewer] = $this->initialInvoice();
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');

        $result = app(EligibilityService::class)->asOf(Membership::sole(), CarbonImmutable::parse($asOf));

        $this->assertSame($eligible, $result['eligible']);
        $this->assertSame($reason, $result['reason']);
    }

    public function test_leap_year_waiting_period_ends_on_march_31(): void
    {
        [$invoice, , $reviewer] = $this->initialInvoice('2028-01-01T04:00:00Z');
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');
        $membership = Membership::sole();
        $eligibility = app(EligibilityService::class);

        $this->assertSame('2028-03-31', $membership->waiting_until->toDateString());
        $this->assertFalse($eligibility->asOf($membership, CarbonImmutable::parse('2028-03-30T15:59:59Z'))['eligible']);
        $this->assertTrue($eligibility->asOf($membership, CarbonImmutable::parse('2028-03-30T16:00:00Z'))['eligible']);
    }

    public function test_december_invoice_keeps_its_year_when_verified_in_january(): void
    {
        [$invoice, , $reviewer] = $this->initialInvoice('2026-12-31T15:00:00Z');
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        $this->travelTo(CarbonImmutable::parse('2026-12-31T16:30:00Z'));

        $this->verify($payment, $reviewer);

        $membership = Membership::sole();
        $this->assertSame(2026, $invoice->fresh()->billing_year);
        $this->assertSame('2027-01-01', $membership->activated_on->toDateString());
        $this->assertSame('2027-04-01', $membership->waiting_until->toDateString());
        $this->assertSame(2026, $membership->periods()->sole()->billing_year);
        $this->assertSame('2026-12-31', $membership->periods()->sole()->ends_on->toDateString());
        $this->assertSame('NO_PAID_PERIOD', app(EligibilityService::class)->asOf(
            $membership, CarbonImmutable::parse('2027-01-01T04:00:00Z')
        )['reason']);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('membership_periods', 1);
    }

    public function test_another_initial_invoice_cannot_activate_the_same_person_twice(): void
    {
        [$invoice, , $reviewer] = $this->initialInvoice();
        $cashier = $this->account('cashier', 'COMMITTEE');
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '70.00');
        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');
        $second = Invoice::factory()->create([
            'person_id' => $invoice->person_id, 'registration_id' => $invoice->registration_id,
            'type' => 'INITIAL', 'total' => '70.00',
        ]);
        $duplicate = app(PaymentService::class)->recordCash($second, $cashier, '70.00');
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/committee/payments/{$duplicate->id}/review", ['decision' => 'VERIFY'])->assertUnprocessable();

        $this->assertSame('PENDING', $duplicate->fresh()->status);
        $this->assertSame('UNPAID', $second->fresh()->status);
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('membership_periods', 1);
        $this->assertDatabaseCount('membership_events', 1);
    }

    public function test_activation_reuses_an_existing_member_number_without_overwriting_it(): void
    {
        [$invoice, $owner, $reviewer] = $this->initialInvoice();
        $member = Member::create(['person_id' => $owner->person_id, 'member_number' => 'AKM-EXISTING']);
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');

        $this->verify($payment, $reviewer);

        $this->assertSame($member->id, Membership::sole()->member_id);
        $this->assertSame('AKM-EXISTING', $member->fresh()->member_number);
        $this->assertDatabaseCount('members', 1);
    }

    public function test_direct_activation_requires_verified_payment_even_when_invoice_is_paid(): void
    {
        [$invoice] = $this->initialInvoice();
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        $invoice->update(['status' => 'PAID']);

        try {
            app(MembershipService::class)->activateInitialPayment($payment);
            $this->fail('Expected unverified payment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('membership', $exception->errors());
        }

        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('members', 0);
    }

    public function test_historical_eligibility_uses_events_at_the_requested_time(): void
    {
        [$invoice, , $reviewer] = $this->initialInvoice();
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');
        $membership = Membership::sole();
        $membership->events()->create([
            'event_key' => 'closed:'.$membership->id, 'type' => 'CLOSED',
            'effective_at' => '2026-05-01 00:00:00', 'recorded_at' => '2026-05-01 00:00:00',
            'actor_id' => $reviewer->id,
        ]);
        $membership->update(['status' => 'CLOSED']);
        $eligibility = app(EligibilityService::class);

        $this->assertSame('ELIGIBLE', $eligibility->asOf($membership, CarbonImmutable::parse('2026-04-15T04:00:00Z'))['reason']);
        $this->assertSame('CLOSED', $eligibility->asOf($membership, CarbonImmutable::parse('2026-05-01T00:00:00Z'))['reason']);
    }

    public static function eventMutations(): array
    {
        return ['update' => ['update'], 'delete' => ['delete']];
    }

    #[DataProvider('eventMutations')]
    public function test_membership_events_cannot_be_rewritten(string $operation): void
    {
        [$invoice, , $reviewer] = $this->initialInvoice();
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');
        $event = MembershipEvent::sole();

        try {
            if ($operation === 'update') {
                $event->update(['type' => 'CLOSED']);
            } else {
                $event->delete();
            }
            $this->fail('Expected membership events to be append-only.');
        } catch (\LogicException $exception) {
            $this->assertSame('Membership events are append-only.', $exception->getMessage());
        }

        $this->assertDatabaseHas('membership_events', ['id' => $event->id, 'type' => 'ACTIVATED']);
    }
}
