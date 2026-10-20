<?php

namespace Tests\Feature;

use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\Registration;
use App\Models\User;
use App\Services\AuthService;
use App\Services\FeeRateService;
use App\Services\InvoiceService;
use App\Services\RegistrationReviewService;
use App\Services\RegistrationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoFeeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InitialInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    private function applicant(): User
    {
        $user = app(AuthService::class)->register([
            'full_name' => 'Test Applicant', 'identity_number' => 'TEST-APPLICANT',
            'email' => 'applicant@alkhair.test', 'password' => 'Password123!',
            'date_of_birth' => '1990-01-01',
        ]);
        $user->markEmailAsVerified();

        return $user;
    }

    private function reviewer(): User
    {
        $user = app(AuthService::class)->register([
            'full_name' => 'Test Reviewer', 'identity_number' => 'TEST-REVIEWER',
            'email' => 'reviewer@alkhair.test', 'password' => 'Password123!',
        ]);
        $user->update(['role' => 'COMMITTEE']);

        return $user;
    }

    private function pending(): Registration
    {
        $applicant = $this->applicant();
        $service = app(RegistrationService::class);
        $registration = $service->createDraft($applicant);
        $service->submit($registration, $applicant);

        return $registration->fresh();
    }

    public function test_approval_creates_two_item_invoice_without_membership_activation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 17:30:00', 'UTC'));
        $this->seed(DemoFeeSeeder::class);
        $registration = $this->pending();
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/committee/registrations/{$registration->id}/review", ['decision' => 'APPROVE'])
            ->assertCreated();

        $invoice = Invoice::with('items')->sole();
        $this->assertSame('APPROVED', $registration->fresh()->status);
        $this->assertSame('INITIAL', $invoice->type);
        $this->assertSame('UNPAID', $invoice->status);
        $this->assertSame('MYR', $invoice->currency);
        $this->assertSame('70.00', $invoice->total);
        $this->assertSame($registration->person_id, $invoice->person_id);
        $this->assertSame($registration->id, $invoice->registration_id);
        $this->assertNull($invoice->member_id);
        $this->assertSame(2027, $invoice->billing_year);
        $this->assertSame('2027-01-31', $invoice->due_date->toDateString());
        $this->assertSame('2026-12-31 17:30:00', $invoice->issued_at->format('Y-m-d H:i:s'));
        $this->assertSame(['REGISTRATION' => '20.00', 'ANNUAL' => '50.00'], $invoice->items->pluck('amount', 'fee_code')->all());
        $this->assertNotEmpty($invoice->invoice_number);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseCount('members', 0);
    }

    public static function requiredFeeCodes(): array
    {
        return ['registration' => ['REGISTRATION'], 'annual' => ['ANNUAL']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredFeeCodes')]
    public function test_missing_fee_rolls_back_approval_review_and_invoice(string $missingCode): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $this->seed(DemoFeeSeeder::class);
        FeeRate::query()->whereHas('feeType', fn ($query) => $query->where('code', $missingCode))->delete();
        $registration = $this->pending();
        Sanctum::actingAs($this->reviewer());

        $this->postJson("/api/committee/registrations/{$registration->id}/review", ['decision' => 'APPROVE'])
            ->assertUnprocessable()->assertJsonValidationErrors('fees');

        $this->assertSame('PENDING_REVIEW', $registration->fresh()->status);
        $this->assertNull($registration->fresh()->approved_submission_id);
        $this->assertDatabaseCount('registration_reviews', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('members', 0);
    }

    public function test_duplicate_approval_and_invoice_retries_do_not_duplicate_billing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $this->seed(DemoFeeSeeder::class);
        $registration = $this->pending();
        Sanctum::actingAs($this->reviewer());
        $url = "/api/committee/registrations/{$registration->id}/review";

        $this->postJson($url, ['decision' => 'APPROVE'])->assertCreated();
        $id = Invoice::sole()->id;
        $this->postJson($url, ['decision' => 'APPROVE'])->assertUnprocessable();
        $again = app(InvoiceService::class)->createInitial($registration);

        $this->assertSame($id, $again->id);
        $this->assertDatabaseCount('registration_reviews', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 2);
    }

    public function test_rate_changes_do_not_recalculate_existing_invoice_items(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $this->seed(DemoFeeSeeder::class);
        $registration = $this->pending();
        app(RegistrationReviewService::class)->review($registration, $this->reviewer(), 'APPROVE');
        FeeRate::query()->update(['amount' => '99.00']);

        $invoice = Invoice::with('items')->sole();

        $this->assertSame('70.00', $invoice->total);
        $this->assertSame(['REGISTRATION' => '20.00', 'ANNUAL' => '50.00'], $invoice->items->pluck('amount', 'fee_code')->all());
    }

    public function test_rate_selection_includes_end_date_and_uses_next_rate_afterwards(): void
    {
        $type = FeeType::factory()->create(['code' => 'ANNUAL']);
        $fees = app(FeeRateService::class);
        $first = $fees->createRate($type, '50.00', '2026-01-01', '2026-12-31');
        $second = $fees->createRate($type, '60.00', '2027-01-01');

        $this->assertSame($first->id, $fees->applicable('ANNUAL', '2026-01-01')->id);
        $this->assertSame($first->id, $fees->applicable('ANNUAL', '2026-12-31')->id);
        $this->assertSame($second->id, $fees->applicable('ANNUAL', '2027-01-01')->id);
    }

    public function test_overlapping_rates_are_rejected(): void
    {
        $type = FeeType::factory()->create();
        $fees = app(FeeRateService::class);
        $fees->createRate($type, '50.00', '2026-01-01', '2026-12-31');

        try {
            $fees->createRate($type, '60.00', '2026-12-31');
            $this->fail('An overlapping rate was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fees', $exception->errors());
        }

        $this->assertDatabaseCount('fee_rates', 1);
    }

    public function test_corrupt_overlapping_configuration_fails_closed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $this->seed(DemoFeeSeeder::class);
        $type = FeeType::where('code', 'ANNUAL')->sole();
        FeeRate::factory()->create(['fee_type_id' => $type->id, 'effective_from' => '2026-02-01']);
        $registration = $this->pending();
        Sanctum::actingAs($this->reviewer());

        $this->postJson("/api/committee/registrations/{$registration->id}/review", ['decision' => 'APPROVE'])
            ->assertUnprocessable()->assertJsonValidationErrors('fees');

        $this->assertSame('PENDING_REVIEW', $registration->fresh()->status);
        $this->assertDatabaseCount('registration_reviews', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_demo_seeding_is_repeatable_and_does_not_overwrite_configured_rates(): void
    {
        $this->seed(DemoFeeSeeder::class);
        FeeRate::whereHas('feeType', fn ($query) => $query->where('code', 'ANNUAL'))->update(['amount' => '75.00']);

        $this->seed(DemoFeeSeeder::class);

        $this->assertDatabaseCount('fee_types', 2);
        $this->assertDatabaseCount('fee_rates', 2);
        $this->assertSame('75.00', FeeRate::whereHas('feeType', fn ($query) => $query->where('code', 'ANNUAL'))->sole()->amount);
    }

    public function test_item_write_failure_rolls_back_every_part_of_approval(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $this->seed(DemoFeeSeeder::class);
        $registration = $this->pending();
        $reviewer = $this->reviewer();
        \App\Models\InvoiceItem::creating(function ($item) {
            if ($item->fee_code === 'ANNUAL') {
                throw new \RuntimeException('Simulated invoice item failure.');
            }
        });

        try {
            app(RegistrationReviewService::class)->review($registration, $reviewer, 'APPROVE');
            $this->fail('Expected invoice item failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated invoice item failure.', $exception->getMessage());
        }

        $this->assertSame('PENDING_REVIEW', $registration->fresh()->status);
        $this->assertNull($registration->fresh()->approved_submission_id);
        $this->assertDatabaseCount('registration_reviews', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
    }

    public function test_pending_application_cannot_be_invoiced_directly(): void
    {
        $registration = $this->pending();

        try {
            app(InvoiceService::class)->createInitial($registration);
            $this->fail('Expected unapproved application to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('registration', $exception->errors());
        }

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_demo_seeding_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        try {
            app(DemoFeeSeeder::class)->run();
            $this->fail('Expected production seeding to be refused.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Demonstration fees may only be seeded locally or in tests.', $exception->getMessage());
        }

        $this->assertDatabaseCount('fee_types', 0);
        $this->assertDatabaseCount('fee_rates', 0);
    }

    public static function invalidRateInputs(): array
    {
        return [
            'negative amount' => ['-1.00', '2026-01-01', null],
            'fractional cent' => ['20.001', '2026-01-01', null],
            'invalid date' => ['20.00', '2026-02-30', null],
            'inverted range' => ['20.00', '2026-02-01', '2026-01-31'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRateInputs')]
    public function test_invalid_rate_configuration_is_rejected(string $amount, string $from, ?string $until): void
    {
        $type = FeeType::factory()->create();

        try {
            app(FeeRateService::class)->createRate($type, $amount, $from, $until);
            $this->fail('Invalid fee configuration was accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertDatabaseCount('fee_rates', 0);
    }

    public function test_rejection_and_change_requests_do_not_require_fees_or_generate_invoices(): void
    {
        $registration = $this->pending();
        app(RegistrationReviewService::class)->review($registration, $this->reviewer(), 'REQUEST_CHANGES', 'Update address.');

        $this->assertSame('CHANGES_REQUESTED', $registration->fresh()->status);
        $this->assertDatabaseCount('invoices', 0);
    }
}
