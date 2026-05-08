<?php

namespace App\Services;

use App\Models\UploadNonce;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadNonceManager
{
    public function create(): string
    {
        $nonce = Str::random(64);

        UploadNonce::create([
            'nonce' => $nonce,
            'created_at' => now(),
        ]);

        return $nonce;
    }

    public function consume(string $nonce): void
    {
        $updated = UploadNonce::query()
            ->whereKey($nonce)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($updated !== 1) {
            throw ValidationException::withMessages([
                'image' => 'This upload was already submitted. Refresh and try again.',
            ]);
        }
    }
}
