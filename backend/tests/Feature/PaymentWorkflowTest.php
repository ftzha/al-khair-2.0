<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentWorkflowTest extends TestCase
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
        ]);
        $user->update(['role' => $role]);

        return $user;
    }

    private function invoice(User $owner): Invoice
    {
        return Invoice::factory()->create(['person_id' => $owner->person_id, 'type' => 'INITIAL', 'total' => '70.00']);
    }

    private function receipt(): UploadedFile
    {
        return UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf');
    }

    public function test_bank_transfer_stays_pending_and_receipt_is_private(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20T07:30:00Z'));
        Storage::fake('local');
        $owner = $this->account('owner');
        $invoice = $this->invoice($owner);
        Sanctum::actingAs($owner);

        $this->postJson("/api/invoices/{$invoice->id}/payments", [
            'amount' => '70.00', 'reference' => 'TRANSFER-TEST', 'receipt' => $this->receipt(),
        ])->assertCreated()->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.has_receipt', true)->assertJsonMissingPath('data.receipt_path');

        $payment = Payment::sole();
        Storage::disk('local')->assertExists($payment->receipt_path);
        $this->assertSame('BANK_TRANSFER', $payment->method);
        $this->assertSame($owner->id, $payment->submitted_by);
        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->getJson("/api/invoices/{$invoice->id}/payments")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.receipt_path');
        $response = $this->get("/api/payments/{$payment->id}/receipt");
        $timestamp = $payment->submitted_at->setTimezone('Asia/Kuala_Lumpur')->format('Ymd_His');
        $prefix = $invoice->invoice_number.'_receipt_'.$timestamp.'_';
        $this->assertMatchesRegularExpression('/^receipts\/'.preg_quote($prefix, '/').'[A-Z0-9]{26}\.pdf$/', $payment->receipt_path);
        $response->assertOk()->assertDownload($prefix.'payment-'.$payment->id.'.pdf');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('members', 0);
    }

    public function test_other_member_cannot_submit_list_or_download_receipts(): void
    {
        Storage::fake('local');
        $owner = $this->account('owner');
        $invoice = $this->invoice($owner);
        $payment = app(PaymentService::class)->submitBankTransfer($invoice, $owner, '70.00', 'TEST', $this->receipt());
        Sanctum::actingAs($this->account('other'));

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => '70.00', 'reference' => 'TEST', 'receipt' => $this->receipt()])->assertForbidden();
        $this->getJson("/api/invoices/{$invoice->id}/payments")->assertForbidden();
        $this->getJson("/api/payments/{$payment->id}/receipt")->assertForbidden();
    }

    public function test_cash_requires_an_independent_verifier_and_marks_invoice_paid_atomically(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $owner = $this->account('owner');
        $invoice = $this->invoice($owner);
        $cashier = $this->account('cashier', 'COMMITTEE');
        Sanctum::actingAs($cashier);
        $id = $this->postJson("/api/committee/payment-invoices/{$invoice->id}/cash", ['amount' => '70.00'])
            ->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data.id');
        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->postJson("/api/committee/payments/{$id}/review", ['decision' => 'VERIFY'])->assertForbidden();
        $reviewer = $this->account('reviewer', 'ADMIN');
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/committee/payments/{$id}/review", ['decision' => 'VERIFY'])
            ->assertOk()->assertJsonPath('data.status', 'VERIFIED');

        $payment = Payment::findOrFail($id);
        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertSame($reviewer->id, $payment->reviewed_by);
        $this->assertSame('2026-10-20 12:00:00', $payment->verified_at->format('Y-m-d H:i:s'));
        $this->assertNull($payment->rejection_reason);
        $this->assertDatabaseCount('members', 0);
        $this->postJson("/api/committee/payments/{$id}/review", ['decision' => 'VERIFY'])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_bank_transfer_can_be_verified_by_a_different_committee_user(): void
    {
        Storage::fake('local');
        $owner = $this->account('owner');
        $invoice = $this->invoice($owner);
        $payment = app(PaymentService::class)->submitBankTransfer($invoice, $owner, '70.00', 'TEST', $this->receipt());
        Sanctum::actingAs($this->account('reviewer', 'COMMITTEE'));

        $this->getJson('/api/committee/payments')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/committee/payments/{$payment->id}")->assertOk()->assertJsonPath('data.can_review', true);
        $this->get("/api/payments/{$payment->id}/receipt")->assertOk();
        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'VERIFY'])->assertOk();

        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertNotNull($payment->fresh()->verified_at);
    }

    public function test_invoice_owner_cannot_verify_cash_recorded_by_someone_else(): void
    {
        $owner = $this->account('owner', 'COMMITTEE');
        $invoice = $this->invoice($owner);
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        Sanctum::actingAs($owner);

        $this->getJson("/api/committee/payments/{$payment->id}")->assertOk()->assertJsonPath('data.can_review', false);
        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'VERIFY'])->assertForbidden();

        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertSame('UNPAID', $invoice->fresh()->status);
    }

    public function test_rejection_requires_reason_preserves_history_and_allows_a_new_initial_payment(): void
    {
        $invoice = $this->invoice($this->account('owner'));
        $cashier = $this->account('cashier', 'COMMITTEE');
        $payment = app(PaymentService::class)->recordCash($invoice, $cashier, '70.00');
        Sanctum::actingAs($this->account('reviewer', 'COMMITTEE'));

        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'REJECT'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'REJECT', 'reason' => ' Not received. '])
            ->assertOk()->assertJsonPath('data.rejection_reason', 'Not received.');

        $this->assertSame('UNPAID', $invoice->fresh()->status);
        $this->assertNull($payment->fresh()->verified_at);
        app(PaymentService::class)->recordCash($invoice, $cashier, '70.00');
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('REJECTED', $payment->fresh()->status);
    }

    public static function incorrectAmounts(): array
    {
        return ['under' => ['69.99'], 'over' => ['70.01'], 'zero' => ['0.00']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incorrectAmounts')]
    public function test_only_full_payments_are_accepted(string $amount): void
    {
        $invoice = $this->invoice($this->account('owner'));
        Sanctum::actingAs($this->account('cashier', 'COMMITTEE'));

        $this->postJson("/api/committee/payment-invoices/{$invoice->id}/cash", ['amount' => $amount])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('UNPAID', $invoice->fresh()->status);
    }

    public function test_pending_payment_blocks_duplicate_and_conflicting_payment_submission(): void
    {
        Storage::fake('local');
        $owner = $this->account('owner');
        $invoice = $this->invoice($owner);
        $cashier = $this->account('cashier', 'COMMITTEE');
        app(PaymentService::class)->recordCash($invoice, $cashier, '70.00');
        Sanctum::actingAs($owner);

        $this->postJson("/api/invoices/{$invoice->id}/payments", ['amount' => '70.00', 'reference' => 'TEST', 'receipt' => $this->receipt()])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame([], Storage::disk('local')->allFiles('receipts'));
    }

    public function test_receipt_is_required_and_unsupported_or_oversized_files_are_rejected(): void
    {
        Storage::fake('local');
        $owner = $this->account('owner');
        $invoice = $this->invoice($owner);
        Sanctum::actingAs($owner);
        $url = "/api/invoices/{$invoice->id}/payments";

        $this->postJson($url, ['amount' => '70.00', 'reference' => 'TEST'])->assertUnprocessable()->assertJsonValidationErrors('receipt');
        $this->postJson($url, ['amount' => '70.00', 'reference' => 'TEST', 'receipt' => UploadedFile::fake()->create('script.html', 10, 'text/html')])->assertUnprocessable()->assertJsonValidationErrors('receipt');
        $this->postJson($url, ['amount' => '70.00', 'reference' => 'TEST', 'receipt' => UploadedFile::fake()->create('large.pdf', 2049, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('receipt');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_guests_and_members_cannot_access_committee_payment_actions(): void
    {
        $invoice = $this->invoice($this->account('owner'));
        $payment = Payment::factory()->create(['invoice_id' => $invoice->id]);
        $this->getJson('/api/committee/payments')->assertUnauthorized();
        $this->getJson("/api/payments/{$payment->id}/receipt")->assertUnauthorized();
        Sanctum::actingAs($this->account('member'));

        $this->getJson('/api/committee/payments')->assertForbidden();
        $this->getJson('/api/committee/payment-invoices')->assertForbidden();
        $this->postJson("/api/committee/payment-invoices/{$invoice->id}/cash", ['amount' => '70.00'])->assertForbidden();
        $this->postJson("/api/committee/payments/{$payment->id}/review", ['decision' => 'VERIFY'])->assertForbidden();
    }

    public function test_receipt_is_removed_if_payment_record_creation_fails(): void
    {
        Storage::fake('local');
        $owner = $this->account('owner');
        $invoice = $this->invoice($owner);
        Payment::creating(fn () => throw new \RuntimeException('Simulated payment failure.'));

        try {
            app(PaymentService::class)->submitBankTransfer($invoice, $owner, '70.00', 'TEST', $this->receipt());
            $this->fail('Expected payment creation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated payment failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('receipts'));
    }

    public function test_invoice_write_failure_rolls_back_payment_verification(): void
    {
        $invoice = $this->invoice($this->account('owner'));
        $payment = app(PaymentService::class)->recordCash($invoice, $this->account('cashier', 'COMMITTEE'), '70.00');
        $reviewer = $this->account('reviewer', 'COMMITTEE');
        Invoice::updating(fn () => throw new \RuntimeException('Simulated invoice failure.'));

        try {
            app(PaymentService::class)->review($payment, $reviewer, 'VERIFY');
            $this->fail('Expected invoice update to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated invoice failure.', $exception->getMessage());
        }

        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->reviewed_by);
        $this->assertNull($payment->fresh()->verified_at);
        $this->assertSame('UNPAID', $invoice->fresh()->status);
    }

    public function test_paid_and_cancelled_invoices_do_not_accept_payments(): void
    {
        $invoice = $this->invoice($this->account('owner'));
        $invoice->update(['status' => 'PAID']);
        Sanctum::actingAs($this->account('cashier', 'COMMITTEE'));
        $url = "/api/committee/payment-invoices/{$invoice->id}/cash";

        $this->postJson($url, ['amount' => '70.00'])->assertUnprocessable();
        $invoice->update(['status' => 'CANCELLED']);
        $this->postJson($url, ['amount' => '70.00'])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
    }
}
