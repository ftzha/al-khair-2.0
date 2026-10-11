<?php

namespace Tests\Feature;

use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use App\Models\User;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    private function createAccount(): void
    {
        app(AuthService::class)->register([
            'full_name' => 'Test Member',
            'identity_number' => '900101-10-1234',
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ]);
    }

    public function test_user_can_login_and_receive_token(): void
    {
        $this->createAccount();

        $response = $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'member@example.com')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->createAccount();

        $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'WrongPassword',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unauthenticated_user_cannot_access_me(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_authenticated_user_can_access_me(): void
    {
        $this->createAccount();

        $token = $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ])->json('data.token');

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Test Member');
    }

    public function test_logout_revokes_current_token(): void
    {
        $this->createAccount();

        $token = $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ])->json('data.token');

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Clear cached authentication state before the next request.
        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }
    
    public function test_suspended_user_cannot_login(): void
    {
        $this->createAccount();

        User::query()->update(['status' => 'SUSPENDED']);

        $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_existing_token_cannot_access_api_after_suspension(): void
    {
        $this->createAccount();

        $token = $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ])->json('data.token');

        User::query()->update(['status' => 'SUSPENDED']);

        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertForbidden();
    }
    
    public function test_disabled_user_cannot_login(): void
    {
        $this->createAccount();

        User::query()->update(['status' => 'DISABLED']);

        $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_existing_token_cannot_access_api_after_disabling_account(): void
    {
        $this->createAccount();

        $token = $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ])->json('data.token');

        User::query()->update(['status' => 'DISABLED']);

        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertForbidden();
    }

    public function test_suspended_user_can_still_logout(): void
    {
        $this->createAccount();

        $token = $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'Password123!',
        ])->json('data.token');

        User::query()->update(['status' => 'SUSPENDED']);

        Auth::forgetGuards();

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
