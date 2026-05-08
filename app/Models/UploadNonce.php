<?php

namespace App\Models;

use Database\Factories\UploadNonceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UploadNonce extends Model
{
    /** @use HasFactory<UploadNonceFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'nonce';

    protected $keyType = 'string';

    protected $fillable = [
        'nonce',
        'consumed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
