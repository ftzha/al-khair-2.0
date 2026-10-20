<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MembershipEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['membership_id', 'event_key', 'type', 'effective_at', 'recorded_at', 'actor_id', 'metadata'];

    protected function casts(): array
    {
        return ['effective_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            throw new LogicException('Membership events are append-only.');
        });
        static::deleting(function (self $event): void {
            throw new LogicException('Membership events are append-only.');
        });
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
