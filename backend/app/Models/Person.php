<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'full_name',
    'date_of_birth',
    'phone_number',
    'address',
])]
#[Hidden(['identity_number', 'identity_lookup_hash'])]
class Person extends Model
{
    use HasFactory;
    
    protected $table = 'persons';

    /**
     * Get the login account associated with this person.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * Return a display-only identity mask without exposing the full identifier.
     */
    public function maskedIdentityNumber(): string
    {
        $identity = $this->identity_number;

        if (! is_string($identity) || $identity === '') {
            return 'Not available';
        }

        if (preg_match('/^\d{12}$/', $identity)) {
            return '******-**-'.substr($identity, -4);
        }

        // Short or non-NRIC identifiers must not accidentally be exposed in full.
        return strlen($identity) > 4 ? str_repeat('*', strlen($identity) - 4).substr($identity, -4) : '****';
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'identity_number' => 'encrypted',
        ];
    }
 
    /**
     * Get the member associated with this person.
     */
    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    /**
     * Get the registrations associated with this person.
     */    
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }
}