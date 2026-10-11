<?php

namespace Tests\Feature;

use App\Models\Registration;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    private function createAccount(
        string $email = 'applicant@example.com',
        string $identity = '900101-10-1234'
    ): User {
        return app(AuthService::class)->register([
            'full_name' => 'Test Applicant',
            'identity_number' => $identity,
            'email' => $email,
            'password' => 'Password123!',
        ]);
    }

    public function test_guest_cannot_create_registration(): void
    {
        $this->postJson('/api/registrations')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_and_list_drafts(): void
    {
        $user = $this->createAccount();
        Sanctum::actingAs($user);

        $this->postJson('/api/registrations')
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT');

        $this->getJson('/api/registrations')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_user_can_update_draft_with_valid_fields(): void
    {
        $user = $this->createAccount();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/registrations')
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/registrations/{$id}/draft", [
            'phone_number' => '0123456789',
            'address' => 'Ulu Yam Bharu',
        ])
            ->assertOk()
            ->assertJsonPath('data.draft_data.address', 'Ulu Yam Bharu');

        $this->getJson("/api/registrations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.draft_data.phone_number', '0123456789');
    }

    public function test_api_rejects_unexpected_draft_fields(): void
    {
        $user = $this->createAccount();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/registrations')
            ->json('data.id');

        $this->patchJson("/api/registrations/{$id}/draft", [
            'address' => 'Valid Address',
            'status' => 'APPROVED',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $this->assertSame(
            'DRAFT',
            Registration::findOrFail($id)->status
        );
    }

    public function test_user_cannot_view_another_users_registration(): void
    {
        $owner = $this->createAccount();

        Sanctum::actingAs($owner);

        $id = $this->postJson('/api/registrations')
            ->json('data.id');

        $other = $this->createAccount(
            'other@example.com',
            '910202-10-5678'
        );

        Sanctum::actingAs($other);

        $this->getJson("/api/registrations/{$id}")
            ->assertForbidden();

        $this->patchJson("/api/registrations/{$id}/draft", [
            'address' => 'Unauthorized Change',
        ])->assertForbidden();

        $this->postJson("/api/registrations/{$id}/submit")
            ->assertForbidden();

        $this->getJson('/api/registrations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_unverified_user_cannot_submit_registration(): void
    {
        $user = $this->createAccount();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/registrations')
            ->json('data.id');

        $this->postJson("/api/registrations/{$id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_verified_user_can_submit_registration(): void
    {
        $user = $this->createAccount();
        $user->markEmailAsVerified();

        Sanctum::actingAs($user);

        $id = $this->postJson('/api/registrations')
            ->json('data.id');

        $this->postJson("/api/registrations/{$id}/submit")
            ->assertCreated()
            ->assertJsonPath('data.revision', 1);

        $this->assertSame(
            'PENDING_REVIEW',
            Registration::findOrFail($id)->status
        );

        $this->postJson("/api/registrations/{$id}/submit")
            ->assertUnprocessable();
    }

    public function test_suspended_user_cannot_access_registration_api(): void
    {
        $user = $this->createAccount();
        $user->update(['status' => 'SUSPENDED']);

        Sanctum::actingAs($user);

        $this->postJson('/api/registrations')
            ->assertForbidden();

        $this->getJson('/api/registrations')
            ->assertForbidden();
    }
}
