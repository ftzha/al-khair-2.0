<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Services\PersonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    public function test_it_creates_a_person_with_encrypted_identity(): void
    {
        $service = app(PersonService::class);

        $person = $service->create([
            'full_name' => 'Test Person',
            'identity_number' => '900101-10-1234',
            'date_of_birth' => '1990-01-01',
            'phone_number' => '0123456789',
        ]);

        $this->assertDatabaseHas('persons', [
            'id' => $person->id,
            'full_name' => 'Test Person',
        ]);

        $this->assertSame(
            '900101101234',
            $person->fresh()->identity_number
        );

        $this->assertNotSame(
            '900101101234',
            $person->getRawOriginal('identity_number')
        );

        $this->assertSame(
            64,
            strlen($person->identity_lookup_hash)
        );
    }

    public function test_it_prevents_duplicate_identity_numbers(): void
    {
        $service = app(PersonService::class);

        $service->create([
            'full_name' => 'First Person',
            'identity_number' => '900101-10-1234',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $service->create([
            'full_name' => 'Second Person',
            'identity_number' => '900101101234',
        ]);
    }
}
