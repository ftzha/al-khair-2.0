<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    private function validPayload(): array
    {
        return [
            'full_name' => 'Test Member',
            'date_of_birth' => '1990-01-01',
            'identity_number' => '900101-10-1234',
            'email' => 'member@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '0123456789',
        ];
    }

    public function test_it_registers_a_user_and_person(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'member@example.com')
            ->assertJsonPath('data.role', 'MEMBER')
            ->assertJsonPath('data.email_verified', false);

        $this->assertDatabaseCount('persons', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('members', 0);

        $user = User::with('person')->firstOrFail();

        $this->assertSame('Test Member', $user->person->full_name);
        $this->assertSame('1990-01-01', $user->person->date_of_birth->toDateString());
        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertNull($user->email_verified_at);

        $this->assertSame(
            '900101101234',
            $user->person->identity_number
        );

        $this->assertNotSame(
            '900101101234',
            $user->person->getRawOriginal('identity_number')
        );
    }

    public function test_registration_requires_date_of_birth(): void
    {
        $payload = $this->validPayload();
        unset($payload['date_of_birth']);

        $this->postJson('/api/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_of_birth');

        $this->assertDatabaseCount('persons', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidBirthDates(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'invalid calendar date' => ['1990-02-30'],
            'wrong format' => ['01/01/1990'],
            'today' => ['2026-10-20'],
            'future' => ['2026-10-21'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBirthDates')]
    public function test_registration_rejects_invalid_birth_dates(?string $birthDate): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-20 12:00:00'));
        $payload = $this->validPayload();
        $payload['date_of_birth'] = $birthDate;

        $this->postJson('/api/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_of_birth');

        $this->assertDatabaseCount('persons', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_preserves_a_manually_confirmed_century(): void
    {
        $payload = $this->validPayload();
        $payload['identity_number'] = '200101-10-1234';
        $payload['date_of_birth'] = '1920-01-01';

        $this->postJson('/api/register', $payload)->assertCreated();

        $this->assertSame('1920-01-01', User::firstOrFail()->person->date_of_birth->toDateString());
    }

    public function test_it_rejects_duplicate_emails(): void
    {
        $this->postJson('/api/register', $this->validPayload())
            ->assertCreated();

        $payload = $this->validPayload();
        $payload['identity_number'] = '900101-10-5678';

        $this->postJson('/api/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('persons', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_it_requires_password_confirmation(): void
    {
        $payload = $this->validPayload();
        $payload['password_confirmation'] = 'DifferentPassword';

        $this->postJson('/api/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseCount('persons', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_does_not_allow_users_to_choose_admin_role(): void
    {
        $payload = $this->validPayload();
        $payload['role'] = 'ADMIN';
        $payload['status'] = 'DISABLED';

        $this->postJson('/api/register', $payload)
            ->assertCreated();

        $user = User::firstOrFail();

        $this->assertSame('MEMBER', $user->role);
        $this->assertSame('ACTIVE', $user->status);
    }
    
    public function test_it_rejects_duplicate_identity_numbers(): void
    {
        $this->postJson('/api/register', $this->validPayload())
            ->assertCreated();

        $payload = $this->validPayload();
        $payload['email'] = 'another@example.com';
        $payload['identity_number'] = '900101101234';

        $this->postJson('/api/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['identity_number']);

        $this->assertDatabaseCount('persons', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_it_rolls_back_person_creation_if_user_creation_fails(): void
    {
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            // No additional setup needed.
        });

        \Illuminate\Support\Facades\Event::listen(
            'eloquent.creating: App\Models\User',
            function () {
                throw new \RuntimeException('Simulated user creation failure');
            }
        );

        try {
            $this->postJson('/api/register', $this->validPayload());
        } catch (\RuntimeException $exception) {
            // Depending on exception handling, the failure may propagate.
        }

        $this->assertDatabaseCount('persons', 0);
        $this->assertDatabaseCount('users', 0);
    }
}
