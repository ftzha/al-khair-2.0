<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\MembershipEvent;
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
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommitteeMembershipApiTest extends TestCase
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
        $committee = $this->account('committee', 'COMMITTEE');
        $admin = $this->account('admin', 'ADMIN');
        $registration = app(RegistrationService::class)->createDraft($owner);
        app(RegistrationService::class)->submit($registration, $owner);
        app(RegistrationReviewService::class)->review($registration, $admin, 'APPROVE');
        $invoice = Invoice::where('registration_id', $registration->id)->sole();
        $payment = app(PaymentService::class)->recordCash($invoice, $committee, '70.00');
        app(PaymentService::class)->review($payment, $admin, 'VERIFY');

        return [Membership::sole(), $owner, $committee, $admin];
    }

    public function test_guests_and_members_cannot_access_staff_memberships(): void
    {
        [$membership, $owner] = $this->activated();
        $this->getJson('/api/committee/memberships')->assertUnauthorized();
        $this->getJson("/api/committee/memberships/{$membership->id}")->assertUnauthorized();
        $this->postJson("/api/committee/memberships/{$membership->id}/close", ['reason' => 'Test'])->assertUnauthorized();
        Sanctum::actingAs($owner);

        $this->getJson('/api/committee/memberships')->assertForbidden();
        $this->getJson("/api/committee/memberships/{$membership->id}")->assertForbidden();
        $this->postJson("/api/committee/memberships/{$membership->id}/close", ['reason' => 'Test'])->assertForbidden();
    }

    public function test_committee_can_search_and_read_history_but_cannot_close(): void
    {
        [$membership, , $committee] = $this->activated();
        Sanctum::actingAs($committee);

        $this->getJson('/api/committee/memberships?search=owner')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $membership->id);
        $this->getJson('/api/committee/memberships?search=not-found')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/committee/memberships/{$membership->id}")->assertOk()
            ->assertJsonPath('data.can_close', false)->assertJsonPath('data.full_name', 'Test owner')
            ->assertJsonCount(1, 'data.periods')->assertJsonCount(1, 'data.events')
            ->assertJsonMissingPath('data.identity_number')->assertJsonMissingPath('data.receipt_path');
        $this->postJson("/api/committee/memberships/{$membership->id}/close", ['reason' => 'Not authorized.'])->assertForbidden();

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertDatabaseCount('membership_events', 1);
    }

    public static function invalidReasons(): array
    {
        return ['missing' => [null], 'blank' => ['   '], 'too long' => [str_repeat('x', 2001)]];
    }

    #[DataProvider('invalidReasons')]
    public function test_admin_closure_requires_valid_reason(?string $reason): void
    {
        [$membership, , , $admin] = $this->activated();
        Sanctum::actingAs($admin);

        $this->postJson("/api/committee/memberships/{$membership->id}/close", ['reason' => $reason])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertDatabaseCount('membership_events', 1);
    }

    public function test_admin_closure_is_immediate_preserves_records_and_does_not_disable_login(): void
    {
        [$membership, $owner, , $admin] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2026-05-31T16:00:00Z'));
        Sanctum::actingAs($admin);

        $this->postJson("/api/committee/memberships/{$membership->id}/close", [
            'reason' => '  Member requested closure.  ', 'effective_at' => '2000-01-01',
        ])->assertOk()->assertJsonPath('data.status', 'CLOSED')->assertJsonPath('data.can_close', false)
            ->assertJsonPath('data.eligibility.eligible', false);

        $event = MembershipEvent::where('type', 'CLOSED')->sole();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame('Member requested closure.', $event->metadata['reason']);
        $this->assertSame('2026-05-31 16:00:00', $event->effective_at->format('Y-m-d H:i:s'));
        $eligibility = app(EligibilityService::class);
        $this->assertTrue($eligibility->asOf($membership, CarbonImmutable::parse('2026-05-31T15:59:59Z'))['eligible']);
        $this->assertFalse($eligibility->asOf($membership, CarbonImmutable::parse('2026-05-31T16:00:00Z'))['eligible']);
        $this->assertSame('ACTIVE', $owner->fresh()->status);
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('membership_periods', 1);
        $this->assertDatabaseCount('membership_events', 2);
        $this->assertSame('PAID', $membership->initialInvoice->status);
        $this->assertSame('VERIFIED', $membership->activationPayment->status);
        $this->postJson('/api/login', ['email' => $owner->email, 'password' => 'Password123!'])->assertOk();
        Sanctum::actingAs($owner);
        $this->getJson('/api/membership')->assertOk()->assertJsonPath('data.status', 'CLOSED');
    }

    public function test_pending_payment_created_after_detail_fetch_blocks_closure(): void
    {
        [$membership, , $committee, $admin] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2027-01-01T04:00:00Z'));
        Sanctum::actingAs($admin);
        $this->getJson("/api/committee/memberships/{$membership->id}")->assertOk()->assertJsonPath('data.can_close', true);
        $invoice = app(InvoiceService::class)->createRenewal($membership);
        $payment = app(PaymentService::class)->recordCash($invoice, $committee, '50.00');

        $this->postJson("/api/committee/memberships/{$membership->id}/close", ['reason' => 'Test closure.'])
            ->assertUnprocessable()->assertJsonValidationErrors('payments');

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertDatabaseCount('membership_events', 1);
        $this->getJson("/api/committee/memberships/{$membership->id}")->assertOk()
            ->assertJsonPath('data.can_close', false)->assertJsonPath('data.pending_payment_count', 1);
    }

    public function test_resolved_rejected_payment_allows_closure_but_no_new_payment_afterward(): void
    {
        [$membership, , $committee, $admin] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2027-01-01T04:00:00Z'));
        $invoice = app(InvoiceService::class)->createRenewal($membership);
        $payment = app(PaymentService::class)->recordCash($invoice, $committee, '50.00');
        app(PaymentService::class)->review($payment, $admin, 'REJECT', 'Not received.');
        Sanctum::actingAs($admin);
        $this->postJson("/api/committee/memberships/{$membership->id}/close", ['reason' => 'Member request.'])->assertOk();

        $this->postJson("/api/committee/payment-invoices/{$invoice->id}/cash", ['amount' => '50.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('membership');

        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertSame('REJECTED', $payment->fresh()->status);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_duplicate_closure_is_rejected_and_services_cannot_reopen_membership(): void
    {
        [$membership, $owner, $committee, $admin] = $this->activated();
        Sanctum::actingAs($admin);
        $this->postJson("/api/committee/memberships/{$membership->id}/close", ['reason' => 'Member request.'])->assertOk();

        $this->postJson("/api/committee/memberships/{$membership->id}/close", ['reason' => 'Second request.'])
            ->assertUnprocessable()->assertJsonValidationErrors('membership');
        app(MembershipService::class)->activateInitialPayment($membership->activationPayment);
        app(MembershipService::class)->syncStatus($membership);
        $this->assertSame('CLOSED', $membership->fresh()->status);
        $this->assertSame(1, MembershipEvent::where('type', 'CLOSED')->count());
        $this->travelTo(CarbonImmutable::parse('2027-01-01T04:00:00Z'));
        Sanctum::actingAs($owner);
        $this->postJson('/api/membership/renewal-invoice')->assertUnprocessable();
        try {
            app(MembershipService::class)->close($membership, $committee, 'Not authorized.');
            $this->fail('Expected service authorization to reject committee closure.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_status_failure_rolls_back_closed_event_and_preserves_membership(): void
    {
        [$membership, , , $admin] = $this->activated();
        Membership::updating(fn () => throw new \RuntimeException('Simulated closure failure.'));

        try {
            app(MembershipService::class)->close($membership, $admin, 'Test closure.');
            $this->fail('Expected closure failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated closure failure.', $exception->getMessage());
        }

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertDatabaseCount('membership_events', 1);
    }

    public function test_list_and_details_agree_on_lapsed_status_before_scheduler_runs(): void
    {
        [$membership, , $committee] = $this->activated();
        $this->travelTo(CarbonImmutable::parse('2027-02-01T04:00:00Z'));
        Sanctum::actingAs($committee);

        $this->getJson('/api/committee/memberships')->assertOk()->assertJsonPath('data.0.status', 'LAPSED');
        $this->getJson("/api/committee/memberships/{$membership->id}")->assertOk()->assertJsonPath('data.status', 'LAPSED');

        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertDatabaseCount('membership_events', 1);
    }
}
