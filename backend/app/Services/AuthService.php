<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Events\Registered;

class AuthService
{
    public function __construct(
        private readonly PersonService $personService
    ) {}

    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $person = $this->personService->create($data);

            return User::create([
                'person_id' => $person->id,
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => 'MEMBER',
                'status' => 'ACTIVE',
            ]);
        });

    // Dispatch the Registered event after the user is created
        event(new Registered($user));

        return $user;
    }

    public function login(array $credentials): array
    {
        $user = User::with('person')
            ->where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'email' => ['This account is not active.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'token' => $token,
            'user' => $user,
        ];
    }
}
