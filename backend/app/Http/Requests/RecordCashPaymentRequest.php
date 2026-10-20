<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordCashPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'ACTIVE' && in_array($this->user()?->role, ['COMMITTEE', 'ADMIN'], true);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
