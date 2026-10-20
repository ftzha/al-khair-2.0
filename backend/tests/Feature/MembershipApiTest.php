<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\User;
use App\Services\AuthService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\RegistrationReviewService;
use App\Services\RegistrationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoFeeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MembershipApiTest extends TestCase
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
    private function activated(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01T04:00:00Z'));
        $this->seed(DemoFeeSeeder::class);
        $owner = $this->account('owner');
        $cashier = $this->account('cashier', 'COMMITTEE');
        $reviewer = $this->account('reviewer', 'ADMIN');
        $registration = app(RegistrationService::class)->createDraft($owner);
        app(RegistrationService::class)->submit($registration, $owner);
        app(RegistrationReviewService::class)->review($registration, $reviewer, 'APPROVE');
        $invoice = Invoice::where('registration_id', $registration->id)->sole();
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '70.00');
        app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');

        return [Membership::sole(), $owner, $cashier, $reviewer];
    }

    public function test_guests_cannot_read_membership_or_generate_renewals(): void
    {
        $this->getJson('/api/membership')->assertUnauthorized();
        $this->postJson('/api/membership/renewal-invoice')->assertUnauthorized();
    }

    public function test_account_without_membership_gets_an_empty_summary(): void
    {
        Sanctum::actingAs($this->account('new'));

        $this->getJson('/api/membership')->assertOk()->assertExactJson(['data' => null]);
        $this->postJson('/api/membership/renewal-invoice')->assertNotFound();

        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_overview_shows_waiting_dates_and_omits_sensitive_identity_and_payment_data(): void
    {
        [$membership, $owner] = $this->activated();
        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/membership');

        $response->assertOk()
            ->assertJsonPath('data.member_number', $membership->member->member_number)
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.activated_on', '2026-01-01')
            ->assertJsonPath('data.waiting_until', '2026-04-01')
            ->assertJsonPath('data.waiting_days_remaining', 90)
            ->assertJsonPath('data.eligibility.eligible', false)
            ->assertJsonPath('data.eligibility.reason', 'WAITING_PERIOD')
            ->assertJsonPath('data.current_period.starts_on', '2026-01-01')
            ->assertJsonPath('data.current_period.ends_on', '2026-12-31')
            ->assertJsonPath('data.renewal.state', 'PAID')
            ->assertJsonPath('data.renewal.can_generate_invoice', false)
            ->assertJsonMissingPath('data.identity_number')
            ->assertJsonMissingPath('data.receipt_path')
            ->assertJsonMissingPath('data.activation_payment');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_membership_summary_uses_current_eligibility_at_malaysia_midnight(): void
    {
        [, $owner] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2026-03-31T16:00:00Z'));
        Sanctum::actingAs($owner);

        $this->getJson('/api/membership')->assertOk()
            ->assertJsonPath('data.eligibility.eligible', true)
            ->assertJsonPath('data.waiting_days_remaining', 0);
    }

    public function test_ownership_is_derived_from_the_session_and_ignores_supplied_ids(): void
    {
        [$membership, $owner] = $this->activated();
        $other = $this->account('other', 'ADMIN');
        Sanctum::actingAs($other);

        $this->getJson('/api/membership?person_id='.$owner->person_id.'&membership_id='.$membership->id)
            ->assertOk()->assertExactJson(['data' => null]);
        $this->postJson('/api/membership/renewal-invoice', ['membership_id' => $membership->id])
            ->assertNotFound();

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_inactive_accounts_cannot_access_membership(): void
    {
        $user = $this->account('inactive');
        $user->update(['status' => 'INACTIVE']);
        Sanctum::actingAs($user);

        $this->getJson('/api/membership')->assertForbidden();
        $this->postJson('/api/membership/renewal-invoice')->assertForbidden();
    }

    public function test_renewal_generation_is_owned_and_idempotent(): void
    {
        [$membership, $owner] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2027-01-01T04:00:00Z'));
        Sanctum::actingAs($owner);
        $this->getJson('/api/membership')->assertOk()
            ->assertJsonPath('data.renewal.state', 'AVAILABLE')
            ->assertJsonPath('data.renewal.can_generate_invoice', true);

        $first = $this->postJson('/api/membership/renewal-invoice')->assertOk();
        $again = $this->postJson('/api/membership/renewal-invoice')->assertOk();

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertDatabaseCount('invoices', 2);
        $invoice = Invoice::findOrFail($first->json('data.id'));
        $this->assertSame($membership->member_id, $invoice->member_id);
        $this->getJson('/api/membership')->assertOk()
            ->assertJsonPath('data.renewal.state', 'PAYMENT_DUE')
            ->assertJsonPath('data.renewal.can_generate_invoice', false)
            ->assertJsonPath('data.renewal.invoice.id', $invoice->id);
    }

    public function test_summary_uses_latest_payment_and_explains_pending_late_verification(): void
    {
        [$membership, $owner, $cashier, $reviewer] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2027-01-01T04:00:00Z'));
        $invoice = app(InvoiceService::class)->createRenewal($membership);
        $rejected = app(PaymentService::class)->recordCash($invoice, $cashier, '50.00');
        app(PaymentService::class)->review($rejected, $reviewer, 'REJECT', 'Not received.');
        app(PaymentService::class)->recordCash($invoice, $cashier, '50.00');
        $this->travelTo(CarbonImmutable::parse('2027-02-01T04:00:00Z'));
        Sanctum::actingAs($owner);

        $this->getJson('/api/membership')->assertOk()
            ->assertJsonPath('data.status', 'LAPSED')
            ->assertJsonPath('data.eligibility.eligible', false)
            ->assertJsonPath('data.eligibility.reason', 'LAPSED')
            ->assertJsonPath('data.renewal.state', 'PENDING_VERIFICATION');

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertDatabaseCount('membership_events', 1);
    }

    public function test_unpaid_gap_is_shown_as_lapsed_during_a_later_january_without_scheduler_writes(): void
    {
        [$membership, $owner] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2028-01-01T04:00:00Z'));
        Sanctum::actingAs($owner);

        $this->getJson('/api/membership')->assertOk()
            ->assertJsonPath('data.status', 'LAPSED')
            ->assertJsonPath('data.renewal.state', 'NOT_AVAILABLE')
            ->assertJsonPath('data.renewal.can_generate_invoice', false);

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertDatabaseCount('membership_events', 1);
    }

    public function test_after_cutoff_no_new_invoice_can_be_generated(): void
    {
        [, $owner] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2027-01-30T16:00:00Z'));
        Sanctum::actingAs($owner);

        $this->getJson('/api/membership')->assertOk()
            ->assertJsonPath('data.renewal.state', 'CUTOFF_PASSED')
            ->assertJsonPath('data.status', 'LAPSED')
            ->assertJsonPath('data.current_period', null);
        $this->postJson('/api/membership/renewal-invoice')
            ->assertUnprocessable()->assertJsonValidationErrors('renewal');

        $this->assertDatabaseCount('invoices', 1);
    }
}
