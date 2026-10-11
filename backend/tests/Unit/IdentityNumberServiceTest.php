<?php

namespace Tests\Unit;

use App\Services\IdentityNumberService;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class IdentityNumberServiceTest extends TestCase
{
    private IdentityNumberService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new IdentityNumberService();

        // Use a dedicated test key, not the real lookup secret.
        config()->set('identity.lookup_key', str_repeat('a', 64));
    }

    public function test_it_normalizes_identity_numbers(): void
    {
        $result = $this->service->normalize(' 900101-10-1234 ');

        $this->assertSame('900101101234', $result);
    }

    public function test_it_generates_consistent_lookup_hashes(): void
    {
        $first = $this->service->lookupHash('900101-10-1234');
        $second = $this->service->lookupHash('900101101234');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    public function test_different_identity_numbers_have_different_hashes(): void
    {
        $first = $this->service->lookupHash('900101101234');
        $second = $this->service->lookupHash('900101101235');

        $this->assertNotSame($first, $second);
    }

    public function test_it_encrypts_identity_numbers(): void
    {
        $encrypted = $this->service->encrypt('900101-10-1234');

        $this->assertNotSame('900101101234', $encrypted);

        $this->assertSame(
            '900101101234',
            Crypt::decryptString($encrypted)
        );
    }

    public function test_it_rejects_missing_lookup_key(): void
    {
        config()->set('identity.lookup_key', null);

        $this->expectException(\RuntimeException::class);

        $this->service->lookupHash('900101101234');
    }
}
