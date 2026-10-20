<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['receipt_path'])]
class Payment extends Model
{
    use HasFactory;

    protected $fillable = ['invoice_id', 'method', 'status', 'amount', 'reference', 'receipt_path', 'receipt_mime', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'verified_at', 'rejection_reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function displayData(): array
    {
        return [
            'id' => $this->id, 'invoice_id' => $this->invoice_id,
            'method' => $this->method, 'status' => $this->status, 'amount' => $this->amount,
            'reference' => $this->reference, 'has_receipt' => $this->receipt_path !== null,
            'submitted_at' => $this->submitted_at->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(), 'verified_at' => $this->verified_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
        ];
    }
}
