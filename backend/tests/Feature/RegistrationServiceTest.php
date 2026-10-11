<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    private function createAccount(): User
    {
        return app(AuthService::class)->register([
            'full_name' => 'Test Applicant',
            'identity_number' => '900101-10-1234',
            'email' => 'applicant@example.com',
            'password' => 'Password123!',
        ]);
    }

    public function test_user_can_create_draft(): void
    {
        $user = $this->createAccount();

        $registration = app(RegistrationService::class)->createDraft($user);

        $this->assertSame('DRAFT', $registration->status);
        $this->assertSame($user->person_id, $registration->person_id);
        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_user_cannot_create_duplicate_active_draft(): void
    {
        $user = $this->createAccount();

        $service = app(RegistrationService::class);
        $service->createDraft($user);

        $this->expectException(ValidationException::class);

        $service->createDraft($user);
    }

    public function test_unverified_user_cannot_submit(): void
    {
        $user = $this->createAccount();

        $service = app(RegistrationService::class);
        $registration = $service->createDraft($user);

        $this->expectException(ValidationException::class);

        $service->submit($registration, $user);
    }

    public function test_verified_user_can_submit_first_revision(): void
    {
        $user = $this->createAccount();
        $user->markEmailAsVerified();

        $service = app(RegistrationService::class);
        $registration = $service->createDraft($user);

        $submission = $service->submit($registration, $user);

        $this->assertSame(1, $submission->revision);
        $this->assertSame('PENDING_REVIEW', $registration->fresh()->status);
        $this->assertSame('Test Applicant', $submission->snapshot['full_name']);
        $this->assertDatabaseCount('registration_submissions', 1);
    }

    public function test_pending_registration_cannot_be_submitted_again(): void
    {
        $user = $this->createAccount();
        $user->markEmailAsVerified();

        $service = app(RegistrationService::class);
        $registration = $service->createDraft($user);

        $service->submit($registration, $user);

        $this->expectException(ValidationException::class);

        $service->submit($registration, $user);
    }
    
    public function test_user_cannot_submit_another_persons_registration(): void
    {
        $owner = $this->createAccount();
        $owner->markEmailAsVerified();

        $registration = app(RegistrationService::class)->createDraft($owner);

        $otherUser = app(AuthService::class)->register([
            'full_name' => 'Another Applicant',
            'identity_number' => '910202-10-5678',
            'email' => 'another@example.com',
            'password' => 'Password123!',
        ]);

        $otherUser->markEmailAsVerified();

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);

        app(RegistrationService::class)->submit($registration, $otherUser);
    }

    public function test_user_can_resubmit_after_changes_requested(): void
    {
        $user = $this->createAccount();
        $user->markEmailAsVerified();

        $service = app(RegistrationService::class);
        $registration = $service->createDraft($user);

        $firstSubmission = $service->submit($registration, $user);

        // Simulates the future committee review service.
        $registration->update([
            'status' => 'CHANGES_REQUESTED',
        ]);

        $secondSubmission = $service->submit($registration, $user);

        $this->assertSame(1, $firstSubmission->revision);
        $this->assertSame(2, $secondSubmission->revision);

        $this->assertSame(
            'PENDING_REVIEW',
            $registration->fresh()->status
        );

        $this->assertDatabaseCount('registration_submissions', 2);

        $this->assertSame(
            1,
            $firstSubmission->fresh()->revision
        );
    }

    public function test_suspended_user_cannot_create_draft(): void
    {
        $user = $this->createAccount();

        $user->update([
            'status' => 'SUSPENDED',
        ]);

        $this->expectException(ValidationException::class);

        app(RegistrationService::class)->createDraft($user);
    }
    
    public function test_user_can_update_draft_contact_details(): void
    {
        $user = $this->createAccount();
        $service = app(RegistrationService::class);
        $registration = $service->createDraft($user);

        $service->updateDraft($registration, $user, [
            'phone_number' => '0123456789',
            'address' => 'Ulu Yam Bharu, Selangor',
        ]);

        $draft = $registration->fresh()->draft_data;

        $this->assertSame('0123456789', $draft['phone_number']);
        $this->assertSame('Ulu Yam Bharu, Selangor', $draft['address']);

        // Editing an application must not change the person's profile.
        $this->assertNull($user->person->fresh()->phone_number);
        $this->assertNull($user->person->fresh()->address);
    }

    public function test_draft_data_is_encrypted_and_identity_fields_cannot_be_changed(): void
    {
        $user = $this->createAccount();
        $service = app(RegistrationService::class);
        $registration = $service->createDraft($user);

        $service->updateDraft($registration, $user, [
            'address' => 'Private Home Address',
            'full_name' => 'Changed Name',
            'identity_number' => '000000000000',
            'status' => 'APPROVED',
        ]);

        $fresh = $registration->fresh();

        $this->assertSame('DRAFT', $fresh->status);
        $this->assertSame('Test Applicant', $user->person->fresh()->full_name);
        $this->assertArrayNotHasKey('full_name', $fresh->draft_data);
        $this->assertArrayNotHasKey('identity_number', $fresh->draft_data);

        $this->assertStringNotContainsString(
            'Private Home Address',
            $fresh->getRawOriginal('draft_data')
        );
    }

    public function test_pending_registration_cannot_be_edited(): void
    {
        $user = $this->createAccount();
        $user->markEmailAsVerified();

        $service = app(RegistrationService::class);
        $registration = $service->createDraft($user);
        $service->submit($registration, $user);

        $this->expectException(ValidationException::class);

        $service->updateDraft($registration, $user, [
            'address' => 'Unauthorized Change',
        ]);
    }

    public function test_resubmission_preserves_previous_snapshot(): void
    {
        $user = $this->createAccount();
        $user->markEmailAsVerified();

        $service = app(RegistrationService::class);
        $registration = $service->createDraft($user);

        $service->updateDraft($registration, $user, [
            'address' => 'Original Address',
        ]);

        $first = $service->submit($registration, $user);

        // Simulate a committee request for changes.
        $registration->update(['status' => 'CHANGES_REQUESTED']);

        $service->updateDraft($registration, $user, [
            'address' => 'Updated Address',
        ]);

        $second = $service->submit($registration, $user);

        $this->assertSame(1, $first->revision);
        $this->assertSame(2, $second->revision);

        $this->assertSame(
            'Original Address',
            $first->fresh()->snapshot['address']
        );

        $this->assertSame(
            'Updated Address',
            $second->fresh()->snapshot['address']
        );
    }
}
