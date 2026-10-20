<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Registration extends Model
{
    protected $fillable = [
        'person_id',
        'type',
        'status',
        'created_by',
        'draft_data',
    ];

    protected function casts(): array
    {
        return [
            'draft_data' => 'encrypted:array',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(RegistrationSubmission::class);
    }

    public function approvedSubmission(): BelongsTo
    {
        return $this->belongsTo(
            RegistrationSubmission::class,
            'approved_submission_id'
        );
    }
}
