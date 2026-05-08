<?php

namespace Database\Factories;

use App\AttemptStatus;
use App\Models\Attempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attempt>
 */
class AttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversion_id' => ConversionFactory::new(),
            'n' => 1,
            'status' => AttemptStatus::Pending,
            'display_filename' => 'example.png',
            'input_mime' => 'image/png',
            'input_ext' => 'png',
            'input_bytes' => 68,
            'input_pixels' => 1,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => AttemptStatus::Ready,
            'pptx_bytes' => 1024,
            'pdf_bytes' => 2048,
            'finished_at' => now(),
        ]);
    }

    public function failed(string $code = 'bridge_error'): static
    {
        return $this->state(fn (): array => [
            'status' => AttemptStatus::Failed,
            'failure_code' => $code,
            'failure_message' => 'We could not convert this image. Please try a different image.',
            'finished_at' => now(),
        ]);
    }
}
