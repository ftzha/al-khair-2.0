<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRegistrationDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
    
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $allowed = ['phone_number', 'address'];

            $unexpected = array_diff(
                array_keys($this->all()),
                $allowed
            );

            if ($unexpected !== []) {
                $validator->errors()->add(
                    'fields',
                    'Unexpected fields: ' . implode(', ', $unexpected)
                );
            }
        });
    }
}
