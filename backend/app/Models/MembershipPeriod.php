<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipPeriod extends Model
{
    use HasFactory;

    protected $fillable = ['membership_id', 'invoice_id', 'payment_id', 'billing_year', 'starts_on', 'ends_on', 'effective_at'];

    protected function casts(): array
    {
        return ['billing_year' => 'integer', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'effective_at' => 'immutable_datetime'];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
