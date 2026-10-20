<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    private function account(string $label): User
    {
        return app(AuthService::class)->register([
            'full_name' => 'Test '.$label, 'identity_number' => 'TEST-'.$label,
            'email' => $label.'@alkhair.test', 'password' => 'Password123!',
        ]);
    }

    public function test_guests_cannot_list_or_view_invoices(): void
    {
        $invoice = Invoice::factory()->create();

        $this->getJson('/api/invoices')->assertUnauthorized();
        $this->getJson("/api/invoices/{$invoice->id}")->assertUnauthorized();
    }

    public function test_owner_can_list_and_view_amount_snapshots_without_internal_or_identity_data(): void
    {
        $owner = $this->account('owner');
        $invoice = Invoice::factory()->create(['person_id' => $owner->person_id, 'type' => 'INITIAL', 'total' => '70.00']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_code' => 'REGISTRATION', 'description' => 'Registration fee', 'amount' => '20.00']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_code' => 'ANNUAL', 'description' => 'Annual membership fee', 'amount' => '50.00']);
        Sanctum::actingAs($owner);

        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total', '70.00')->assertJsonPath('total', 1)
            ->assertJsonMissingPath('data.0.billing_key')->assertJsonMissingPath('data.0.person_id');
        $this->getJson("/api/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.total', '70.00')->assertJsonPath('data.due_date', '2026-11-19')
            ->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.amount', '20.00')
            ->assertJsonPath('data.items.1.amount', '50.00')
            ->assertJsonMissingPath('data.billing_key')->assertJsonMissingPath('data.person')
            ->assertJsonMissingPath('data.items.0.fee_rate_id');
    }

    public function test_other_person_cannot_view_invoice_and_list_is_filtered_by_owner(): void
    {
        $owner = $this->account('owner');
        $invoice = Invoice::factory()->create(['person_id' => $owner->person_id]);
        Sanctum::actingAs($this->account('other'));

        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/invoices/{$invoice->id}")->assertForbidden();
    }

    public function test_committee_role_does_not_bypass_member_invoice_ownership(): void
    {
        $invoice = Invoice::factory()->create();
        $committee = $this->account('committee');
        $committee->update(['role' => 'COMMITTEE']);
        Sanctum::actingAs($committee);

        $this->getJson("/api/invoices/{$invoice->id}")->assertForbidden();
    }

    public function test_inactive_account_cannot_access_invoices(): void
    {
        $user = $this->account('inactive');
        $invoice = Invoice::factory()->create(['person_id' => $user->person_id]);
        $user->update(['status' => 'DISABLED']);
        Sanctum::actingAs($user);

        $this->getJson('/api/invoices')->assertForbidden();
        $this->getJson("/api/invoices/{$invoice->id}")->assertForbidden();
    }

    public function test_missing_invoice_returns_not_found(): void
    {
        Sanctum::actingAs($this->account('owner'));

        $this->getJson('/api/invoices/999999')->assertNotFound();
    }

    public function test_invoice_listing_is_paginated(): void
    {
        $user = $this->account('owner');
        Invoice::factory()->count(16)->create(['person_id' => $user->person_id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/invoices?page=2')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('current_page', 2)->assertJsonPath('last_page', 2)->assertJsonPath('total', 16);
    }
}
