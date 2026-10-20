<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'ACTIVE' && $this->user()?->role === 'ADMIN';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
