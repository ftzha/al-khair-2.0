<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitBankTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'ACTIVE'
            && $this->route('invoice')?->person_id === $this->user()?->person_id;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'reference' => ['required', 'string', 'max:100'],
            'receipt' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
        ];
    }
}
