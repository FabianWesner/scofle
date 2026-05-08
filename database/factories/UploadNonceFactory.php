<?php

namespace Database\Factories;

use App\Models\UploadNonce;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UploadNonce>
 */
class UploadNonceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nonce' => Str::random(64),
            'created_at' => now(),
        ];
    }
}
