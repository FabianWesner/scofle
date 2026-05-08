<?php

namespace App\Models;

use Database\Factories\SessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    /** @use HasFactory<SessionFactory> */
    use HasFactory;

    protected $fillable = [
        'token',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Conversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }
}
