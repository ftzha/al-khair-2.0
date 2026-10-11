<?php

namespace Tests\Feature;

use App\Models\Registration;
use App\Models\RegistrationSubmission;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationRelationshipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    public function test_registration_relationships_and_encrypted_snapshot(): void
    {
        $user = app(AuthService::class)->register([
            'full_name' => 'Test Applicant',
            'identity_number' => '900101-10-1234',
            'email' => 'applicant@example.com',
            'password' => 'Password123!',
        ]);

        $registration = Registration::create([
            'person_id' => $user->person_id,
            'type' => 'NEW',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);

        $submission = RegistrationSubmission::create([
            'registration_id' => $registration->id,
            'revision' => 1,
            'snapshot' => [
                'full_name' => 'Test Applicant',
                'address' => 'Ulu Yam Bharu',
            ],
            'submitted_by' => $user->id,
            'submitted_at' => now(),
        ]);

        $this->assertTrue(
            $user->person->registrations->contains($registration)
        );

        $this->assertTrue(
            $user->createdRegistrations->contains($registration)
        );

        $this->assertTrue(
            $registration->submissions->contains($submission)
        );

        $this->assertSame(
            'Test Applicant',
            $submission->fresh()->snapshot['full_name']
        );

        $this->assertNotEquals(
            json_encode($submission->snapshot),
            $submission->getRawOriginal('snapshot')
        );

        $this->assertSame(
            $user->id,
            $submission->submitter->id
        );
    }
}
