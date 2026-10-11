<?php

namespace App\Services;

use App\Models\Person;
use Illuminate\Support\Facades\DB;

class PersonService
{
    public function __construct(
        private readonly IdentityNumberService $identityService
    ) {}

    public function create(array $data): Person
    {
        return DB::transaction(function () use ($data) {
            $identityNumber = $this->identityService->normalize(
                $data['identity_number']
            );

            $person = new Person();

            $person->full_name = $data['full_name'];
            $person->date_of_birth = $data['date_of_birth'] ?? null;
            $person->phone_number = $data['phone_number'] ?? null;
            $person->address = $data['address'] ?? null;

            // Encrypted automatically by the Person model's cast.
            $person->identity_number = $identityNumber;

            // Deterministic keyed hash for duplicate detection.
            $person->identity_lookup_hash =
                $this->identityService->lookupHash($identityNumber);

            $person->save();

            return $person;
        });
    }
}
