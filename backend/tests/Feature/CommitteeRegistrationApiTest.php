<?php

namespace Tests\Feature;

use App\Models\Registration;
use App\Models\User;
use App\Services\AuthService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommitteeRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    private function createAccount(
        string $email,
        string $identity,
        string $role = 'MEMBER'
    ): User {
        $user = app(AuthService::class)->register([
            'full_name' => 'Test User',
            'identity_number' => $identity,
            'email' => $email,
            'password' => 'Password123!',
        ]);

        $user->update(['role' => $role]);

        return $user;
    }

    private function pendingRegistration(): array
    {
        $applicant = $this->createAccount(
            'applicant@example.com',
            '900101-10-1234'
        );

        $applicant->markEmailAsVerified();

        $service = app(RegistrationService::class);
        $registration = $service->createDraft($applicant);
        $submission = $service->submit($registration, $applicant);

        return [$registration, $submission, $applicant];
    }

    private function committee(): User
    {
        return $this->createAccount(
            'committee@example.com',
            '910202-10-5678',
            'COMMITTEE'
        );
    }

    public function test_guest_cannot_access_committee_routes(): void
    {
        $this->getJson('/api/committee/registrations')
            ->assertUnauthorized();
    }

    public function test_member_cannot_access_committee_registrations(): void
    {
        [$registration] = $this->pendingRegistration();

        $member = $this->createAccount(
            'member@example.com',
            '920303-10-9876'
        );

        Sanctum::actingAs($member);

        $this->getJson('/api/committee/registrations')
            ->assertForbidden();

        $this->getJson(
            "/api/committee/registrations/{$registration->id}"
        )->assertForbidden();

        $this->postJson(
            "/api/committee/registrations/{$registration->id}/review",
            ['decision' => 'APPROVE']
        )->assertForbidden();
    }

    public function test_committee_can_list_and_view_pending_registrations(): void
    {
        [$registration] = $this->pendingRegistration();

        Sanctum::actingAs($this->committee());

        $this->getJson('/api/committee/registrations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $registration->id);

        $this->getJson(
            "/api/committee/registrations/{$registration->id}"
        )
            ->assertOk()
            ->assertJsonPath('data.submissions.0.revision', 1)
            ->assertJsonPath(
                'data.submissions.0.snapshot.full_name',
                'Test User'
            );
    }

    public function test_committee_can_approve_registration(): void
    {
        [$registration, $submission] = $this->pendingRegistration();

        Sanctum::actingAs($this->committee());

        $this->postJson(
            "/api/committee/registrations/{$registration->id}/review",
            ['decision' => 'APPROVE']
        )
            ->assertCreated()
            ->assertJsonPath('data.decision', 'APPROVE');

        $fresh = Registration::findOrFail($registration->id);

        $this->assertSame('APPROVED', $fresh->status);
        $this->assertSame(
            $submission->id,
            $fresh->approved_submission_id
        );
    }

    public function test_committee_can_request_changes(): void
    {
        [$registration] = $this->pendingRegistration();

        Sanctum::actingAs($this->committee());

        $this->postJson(
            "/api/committee/registrations/{$registration->id}/review",
            [
                'decision' => 'REQUEST_CHANGES',
                'reason' => 'Please update your address.',
            ]
        )
            ->assertCreated()
            ->assertJsonPath('data.decision', 'REQUEST_CHANGES');

        $this->assertSame(
            'CHANGES_REQUESTED',
            $registration->fresh()->status
        );
    }

    public function test_rejection_requires_reason(): void
    {
        [$registration] = $this->pendingRegistration();

        Sanctum::actingAs($this->committee());

        $this->postJson(
            "/api/committee/registrations/{$registration->id}/review",
            ['decision' => 'REJECT']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame(
            'PENDING_REVIEW',
            $registration->fresh()->status
        );
    }

    public function test_committee_cannot_review_own_submission(): void
    {
        [$registration, , $applicant] = $this->pendingRegistration();

        $applicant->update(['role' => 'COMMITTEE']);

        Sanctum::actingAs($applicant);

        $this->postJson(
            "/api/committee/registrations/{$registration->id}/review",
            ['decision' => 'APPROVE']
        )->assertForbidden();

        $this->assertSame(
            'PENDING_REVIEW',
            $registration->fresh()->status
        );
    }

    public function test_committee_cannot_review_registration_twice(): void
    {
        [$registration] = $this->pendingRegistration();

        Sanctum::actingAs($this->committee());

        $url = "/api/committee/registrations/{$registration->id}/review";

        $this->postJson($url, [
            'decision' => 'APPROVE',
        ])->assertCreated();

        $this->postJson($url, [
            'decision' => 'REJECT',
            'reason' => 'Second decision.',
        ])->assertUnprocessable();
    }
}
