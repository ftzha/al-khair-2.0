<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegistrationSubmission extends Model
{
    protected $fillable = [
        'registration_id',
        'revision',
        'snapshot',
        'submitted_by',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'encrypted:array',
            'submitted_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RegistrationReview::class);
    }
}
