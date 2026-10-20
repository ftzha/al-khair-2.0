<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\MembershipEvent;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuthService;
use App\Services\RegistrationReviewService;
use App\Services\RegistrationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoFeeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipBackfillTest extends TestCase
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

    /** Represent valid records from before M6 automatic activation. @return array{Invoice, Payment} */
    private function legacy(string $suffix = 'one'): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01T04:00:00Z'));
        $this->seed(DemoFeeSeeder::class);
        $owner = $this->account('owner-'.$suffix);
        $cashier = $this->account('cashier-'.$suffix, 'COMMITTEE');
        $reviewer = $this->account('reviewer-'.$suffix, 'ADMIN');
        $registration = app(RegistrationService::class)->createDraft($owner);
        app(RegistrationService::class)->submit($registration, $owner);
        app(RegistrationReviewService::class)->review($registration, $reviewer, 'APPROVE');
        $invoice = Invoice::where('registration_id', $registration->id)->sole();
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id, 'amount' => '70.00', 'status' => 'VERIFIED',
            'submitted_by' => $cashier->id, 'submitted_at' => '2026-01-01 04:00:00',
            'reviewed_by' => $reviewer->id, 'reviewed_at' => '2026-01-02 16:30:00',
            'verified_at' => '2026-01-02 16:30:00',
        ]);
        $invoice->update(['status' => 'PAID']);
        $this->travelTo(CarbonImmutable::parse('2026-10-21T04:00:00Z'));

        return [$invoice, $payment];
    }

    public function test_preview_is_default_and_retains_no_activation_changes(): void
    {
        [$invoice, $payment] = $this->legacy();

        $this->artisan('memberships:backfill-initial')->assertSuccessful();

        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('membership_periods', 0);
        $this->assertDatabaseCount('membership_events', 0);
        $this->assertNull($invoice->fresh()->member_id);
        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertSame('VERIFIED', $payment->fresh()->status);
    }

    public function test_apply_preserves_original_dates_and_repeated_runs_do_not_duplicate(): void
    {
        [$invoice, $payment] = $this->legacy();

        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertSuccessful();

        $membership = Membership::sole();
        $number = $membership->member->member_number;
        $this->assertSame('2026-01-03', $membership->activated_on->toDateString());
        $this->assertSame('2026-04-03', $membership->waiting_until->toDateString());
        $this->assertSame('2026-01-02 16:30:00', $membership->activated_at->format('Y-m-d H:i:s'));
        $this->assertSame($membership->member_id, $invoice->fresh()->member_id);
        $this->assertSame(2026, $membership->periods()->sole()->billing_year);
        $event = $membership->events()->sole();
        $this->assertSame('2026-01-02 16:30:00', $event->effective_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 04:00:00', $event->recorded_at->format('Y-m-d H:i:s'));
        $this->assertSame('VERIFIED', $payment->fresh()->status);

        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertSuccessful();
        $this->assertSame($number, $membership->member->fresh()->member_number);
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('membership_periods', 1);
        $this->assertDatabaseCount('membership_events', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_existing_closed_memberships_are_left_unchanged(): void
    {
        $this->legacy();
        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertSuccessful();
        $membership = Membership::sole();
        $membership->update(['status' => 'CLOSED']);

        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertSuccessful();

        $this->assertSame('CLOSED', $membership->fresh()->status);
        $this->assertDatabaseCount('membership_events', 1);
    }

    public function test_unapproved_registration_is_not_activated(): void
    {
        [$invoice] = $this->legacy();
        $invoice->registration->update(['status' => 'REJECTED']);

        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertFailed();

        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('membership_periods', 0);
        $this->assertDatabaseCount('membership_events', 0);
        $this->assertNull($invoice->fresh()->member_id);
    }

    public function test_future_verification_is_not_backfilled(): void
    {
        [, $payment] = $this->legacy();
        $payment->update(['verified_at' => '2027-01-01 00:00:00']);

        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertFailed();

        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('members', 0);
    }

    public function test_unpaid_pending_records_are_ignored(): void
    {
        [$invoice, $payment] = $this->legacy();
        $invoice->update(['status' => 'UNPAID']);
        $payment->update(['status' => 'PENDING', 'verified_at' => null]);

        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertSuccessful();

        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_renewal_payments_are_not_used_for_initial_activation(): void
    {
        [$invoice] = $this->legacy();
        $invoice->update(['type' => 'RENEWAL']);

        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_old_paid_year_is_preserved_and_current_lapse_is_synchronized(): void
    {
        [$invoice] = $this->legacy();
        $this->travelTo(CarbonImmutable::parse('2027-02-01T04:00:00Z'));

        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertSuccessful();

        $membership = Membership::sole();
        $this->assertSame('LAPSED', $membership->status);
        $this->assertSame(2026, $invoice->fresh()->billing_year);
        $this->assertSame(2026, $membership->periods()->sole()->billing_year);
        $this->assertSame('2026-04-03', $membership->waiting_until->toDateString());
        $this->assertSame('2027-01-30 16:00:00', $membership->events()->where('type', 'LAPSED')->sole()->effective_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_failure_rolls_back_one_activation_and_other_valid_records_can_complete(): void
    {
        [$badInvoice] = $this->legacy('bad');
        [$goodInvoice] = $this->legacy('good');
        MembershipEvent::creating(function (MembershipEvent $event) use ($badInvoice): void {
            if (($event->metadata['invoice_id'] ?? null) === $badInvoice->id) {
                throw new \RuntimeException('Simulated backfill event failure.');
            }
        });

        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertFailed();

        $this->assertNull($badInvoice->fresh()->member_id);
        $this->assertNotNull($goodInvoice->fresh()->member_id);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('membership_events', 1);
        $this->assertDatabaseCount('membership_periods', 1);
        $this->assertSame($goodInvoice->id, Membership::sole()->initial_invoice_id);
    }

    public function test_duplicate_legacy_payments_do_not_duplicate_membership(): void
    {
        [$invoice, $payment] = $this->legacy();
        Payment::factory()->create([
            'invoice_id' => $invoice->id, 'amount' => '70.00', 'status' => 'VERIFIED',
            'submitted_by' => $payment->submitted_by, 'submitted_at' => $payment->submitted_at,
            'reviewed_by' => $payment->reviewed_by, 'reviewed_at' => $payment->reviewed_at,
            'verified_at' => $payment->verified_at,
        ]);

        $this->artisan('memberships:backfill-initial')->assertSuccessful();
        $this->assertDatabaseCount('memberships', 0);
        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('membership_periods', 1);
        $this->assertDatabaseCount('membership_events', 1);
    }

    public function test_backfill_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('memberships:backfill-initial', ['--apply' => true])->assertFailed();

        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('members', 0);
    }
}
