<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Membership extends Model
{
    use HasFactory;

    protected $fillable = ['member_id', 'initial_invoice_id', 'activation_payment_id', 'status', 'activated_at', 'activated_on', 'waiting_until'];

    protected function casts(): array
    {
        return ['activated_at' => 'immutable_datetime', 'activated_on' => 'immutable_date', 'waiting_until' => 'immutable_date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function initialInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'initial_invoice_id');
    }

    public function activationPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'activation_payment_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(MembershipPeriod::class)->orderBy('billing_year');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MembershipEvent::class)->orderBy('effective_at')->orderBy('id');
    }
}
