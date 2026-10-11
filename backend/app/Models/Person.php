<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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