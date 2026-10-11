<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'ACTIVE'
            && in_array(
                $this->user()?->role,
                ['COMMITTEE', 'ADMIN'],
                true
            );
    }

    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::in(['APPROVE', 'REJECT', 'REQUEST_CHANGES']),
            ],
            'reason' => [
                'nullable',
                'string',
                'max:2000',
                'required_unless:decision,APPROVE',
            ],
        ];
    }
}
