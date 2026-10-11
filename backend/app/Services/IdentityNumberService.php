<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

class IdentityNumberService
{
    public function normalize(string $identityNumber): string
    {
        return strtoupper(
            preg_replace('/[\s-]+/', '', trim($identityNumber))
        );
    }

    public function encrypt(string $identityNumber): string
    {
        return Crypt::encryptString(
            $this->normalize($identityNumber)
        );
    }

    public function lookupHash(string $identityNumber): string
    {
        $normalized = $this->normalize($identityNumber);

        return hash_hmac(
            'sha256',
            $normalized,
            $this->lookupKey()
        );
    }

    private function lookupKey(): string
    {
        $key = config('identity.lookup_key');

        if (! is_string($key) || $key === '') {
            throw new \RuntimeException(
                'Identity lookup key is not configured.'
            );
        }

        return $key;
    }
}