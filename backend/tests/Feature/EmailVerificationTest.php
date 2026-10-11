<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
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
            'full_name' => 'Test Member',
            'identity_number' => '900101-10-1234',
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ]);
    }

    private function verificationUrl(User $user, int $expires = 60): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($expires),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );
    }

    public function test_registration_sends_verification_notification(): void
    {
        Notification::fake();

        $user = $this->createAccount();

        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_valid_link_verifies_email(): void
    {
        Notification::fake();

        $user = $this->createAccount();

        $this->getJson($this->verificationUrl($user))
            ->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_expired_link_is_rejected(): void
    {
        Notification::fake();

        $user = $this->createAccount();

        $this->getJson($this->verificationUrl($user, -1))
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_tampered_link_is_rejected(): void
    {
        Notification::fake();

        $user = $this->createAccount();

        $url = $this->verificationUrl($user);
        $tamperedUrl = $url . '&extra=changed';

        $this->getJson($tamperedUrl)
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_authenticated_user_can_resend_verification_email(): void
    {
        Notification::fake();

        $user = $this->createAccount();

        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/email/verification-notification')
            ->assertOk();

        Notification::assertSentToTimes($user, VerifyEmail::class, 2);
    }
    
    public function test_suspended_user_cannot_verify_email(): void
    {
        Notification::fake();

        $user = $this->createAccount();

        $url = $this->verificationUrl($user);

        $user->update(['status' => 'SUSPENDED']);

        $this->getJson($url)
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_already_verified_email_does_not_dispatch_event_again(): void
    {
        Notification::fake();

        $user = $this->createAccount();

        $url = $this->verificationUrl($user);

        $this->getJson($url)->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);

        \Illuminate\Support\Facades\Event::fake([
            \Illuminate\Auth\Events\Verified::class,
        ]);

        $this->getJson($url)->assertOk();

        \Illuminate\Support\Facades\Event::assertNotDispatched(
            \Illuminate\Auth\Events\Verified::class
        );
    }
}
