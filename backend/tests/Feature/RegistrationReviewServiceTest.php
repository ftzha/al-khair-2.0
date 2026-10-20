<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Services\RegistrationReviewService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RegistrationReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.lookup_key', str_repeat('a', 64));
        $this->seed(\Database\Seeders\DemoFeeSeeder::class);
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

    public function test_committee_can_approve_pending_registration(): void
    {
        [$registration, $submission] = $this->pendingRegistration();

        $reviewer = $this->createAccount(
            'committee@example.com',
            '910202-10-5678',
            'COMMITTEE'
        );

        $review = app(RegistrationReviewService::class)
            ->review($registration, $reviewer, 'APPROVE');

        $this->assertSame('APPROVE', $review->decision);
        $this->assertSame('APPROVED', $registration->fresh()->status);
        $this->assertSame(
            $submission->id,
            $registration->fresh()->approved_submission_id
        );
    }

    public function test_committee_can_request_changes(): void
    {
        [$registration] = $this->pendingRegistration();

        $reviewer = $this->createAccount(
            'committee@example.com',
            '910202-10-5678',
            'COMMITTEE'
        );

        $review = app(RegistrationReviewService::class)->review(
            $registration,
            $reviewer,
            'REQUEST_CHANGES',
            'Please confirm your address.'
        );

        $this->assertSame('REQUEST_CHANGES', $review->decision);
        $this->assertSame(
            'CHANGES_REQUESTED',
            $registration->fresh()->status
        );
    }

    public function test_committee_can_reject_with_reason(): void
    {
        [$registration] = $this->pendingRegistration();

        $reviewer = $this->createAccount(
            'committee@example.com',
            '910202-10-5678',
            'COMMITTEE'
        );

        app(RegistrationReviewService::class)->review(
            $registration,
            $reviewer,
            'REJECT',
            'Application does not meet requirements.'
        );

        $this->assertSame('REJECTED', $registration->fresh()->status);
    }

    public function test_member_cannot_review_registration(): void
    {
        [$registration] = $this->pendingRegistration();

        $member = $this->createAccount(
            'member@example.com',
            '910202-10-5678'
        );

        $this->expectException(HttpException::class);

        app(RegistrationReviewService::class)->review(
            $registration,
            $member,
            'APPROVE'
        );
    }

    public function test_reviewer_cannot_review_own_submission(): void
    {
        [$registration, , $applicant] = $this->pendingRegistration();

        $applicant->update(['role' => 'COMMITTEE']);

        $this->expectException(HttpException::class);

        app(RegistrationReviewService::class)->review(
            $registration,
            $applicant,
            'APPROVE'
        );
    }

    public function test_rejection_requires_reason(): void
    {
        [$registration] = $this->pendingRegistration();

        $reviewer = $this->createAccount(
            'committee@example.com',
            '910202-10-5678',
            'COMMITTEE'
        );

        $this->expectException(ValidationException::class);

        app(RegistrationReviewService::class)->review(
            $registration,
            $reviewer,
            'REJECT'
        );
    }

    public function test_registration_cannot_be_reviewed_twice(): void
    {
        [$registration] = $this->pendingRegistration();

        $reviewer = $this->createAccount(
            'committee@example.com',
            '910202-10-5678',
            'COMMITTEE'
        );

        $service = app(RegistrationReviewService::class);

        $service->review($registration, $reviewer, 'APPROVE');

        $this->expectException(ValidationException::class);

        $service->review($registration, $reviewer, 'REJECT', 'Changed my mind.');
    }
}
