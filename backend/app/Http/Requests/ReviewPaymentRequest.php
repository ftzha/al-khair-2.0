<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'ACTIVE' && in_array($this->user()?->role, ['COMMITTEE', 'ADMIN'], true);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['VERIFY', 'REJECT'])],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:decision,REJECT'],
        ];
    }
}
