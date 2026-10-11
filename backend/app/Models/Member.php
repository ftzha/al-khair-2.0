<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['person_id', 'member_number'])]
class Member extends Model
{
    use HasFactory;

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}