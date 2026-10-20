<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeRate extends Model
{
    use HasFactory;

    protected $fillable = ['fee_type_id', 'amount', 'currency', 'effective_from', 'effective_until'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'effective_from' => 'immutable_date', 'effective_until' => 'immutable_date'];
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }
}
