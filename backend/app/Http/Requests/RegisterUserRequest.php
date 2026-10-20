<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

use App\Services\IdentityNumberService;
use Illuminate\Validation\Rule;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'identity_number' => [
                'required',
                'string',
                'max:50',
                function ($attribute, $value, $fail) {
                    $identityService = app(IdentityNumberService::class);

                    $hash = $identityService->lookupHash($value);

                    if (\App\Models\Person::where('identity_lookup_hash', $hash)->exists()) {
                        $fail('This identity number is already registered.');
                    }
                },
            ],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
        ];
    }
}
