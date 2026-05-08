<?php

namespace App\Models;

use Database\Factories\ConversionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversion extends Model
{
    /** @use HasFactory<ConversionFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'session_id',
        'total_bytes',
    ];

    protected $attributes = [
        'total_bytes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'total_bytes' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    /**
     * @return HasMany<Attempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    public function hasInflightAttempt(): bool
    {
        return $this->attempts()
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }
}
