<?php

namespace App\Console\Commands;

use App\AttemptStatus;
use App\FailureCode;
use App\Models\Attempt;
use App\Models\Conversion;
use App\Services\ConversionLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('conversions:reap')]
#[Description('Delete expired temporary conversions and enforce the local byte ceiling.')]
class ReapConversionsCommand extends Command
{
    public function handle(ConversionLifecycle $conversions): int
    {
        $this->markInterruptedAttempts();
        $this->enforceByteCeiling($conversions);
        $this->deleteExpiredConversions($conversions);

        return self::SUCCESS;
    }

    private function markInterruptedAttempts(): void
    {
        Attempt::query()
            ->where('status', AttemptStatus::Running->value)
            ->where('heartbeat_at', '<', now()->subSeconds((int) config('conversion.timeout')))
            ->each(function (Attempt $attempt): void {
                $attempt->forceFill([
                    'status' => AttemptStatus::Failed,
                    'failure_code' => FailureCode::Interrupted->value,
                    'failure_message' => FailureCode::Interrupted->message(),
                    'finished_at' => now(),
                ])->save();

                Log::warning('stale conversion attempt marked interrupted', [
                    'attempt_id' => $attempt->id,
                    'conversion_uuid' => $attempt->conversion->uuid,
                ]);
            });
    }

    private function enforceByteCeiling(ConversionLifecycle $conversions): void
    {
        while ((int) Conversion::query()->sum('total_bytes') > config('conversion.tmp_bytes_cap')) {
            $conversion = $this->oldestEvictableConversion();

            if (! $conversion instanceof Conversion) {
                $this->warn('Temporary byte ceiling exceeded but no evictable conversion exists.');

                return;
            }

            $bytes = $conversion->total_bytes;
            $uuid = $conversion->uuid;
            $conversions->delete($conversion);
            $this->info("Deleted {$uuid} for byte ceiling; freed {$bytes} bytes.");
            Log::info('conversion reaped for byte ceiling', ['conversion_uuid' => $uuid, 'bytes_freed' => $bytes]);
        }
    }

    private function deleteExpiredConversions(ConversionLifecycle $conversions): void
    {
        Conversion::query()
            ->where('created_at', '<', now()->subHours((int) config('conversion.ttl_hours')))
            ->oldest('created_at')
            ->each(function (Conversion $conversion) use ($conversions): void {
                if ($conversion->hasInflightAttempt()) {
                    $this->warn("Skipped {$conversion->uuid}; in-flight job.");
                    Log::info('conversion reaper skipped in-flight conversion', ['conversion_uuid' => $conversion->uuid]);

                    return;
                }

                $bytes = $conversion->total_bytes;
                $uuid = $conversion->uuid;
                $ageHours = (int) $conversion->created_at->diffInHours(now());
                $conversions->delete($conversion);
                $this->info("Deleted {$uuid}; age {$ageHours} hours; freed {$bytes} bytes.");
                Log::info('conversion reaped for ttl', [
                    'conversion_uuid' => $uuid,
                    'age_hours' => $ageHours,
                    'bytes_freed' => $bytes,
                ]);
            });
    }

    private function oldestEvictableConversion(): ?Conversion
    {
        return Conversion::query()
            ->oldest('created_at')
            ->get()
            ->first(fn (Conversion $conversion): bool => ! $conversion->hasInflightAttempt());
    }
}
