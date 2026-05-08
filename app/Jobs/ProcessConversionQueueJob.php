<?php

namespace App\Jobs;

use App\AttemptStatus;
use App\Models\Attempt;
use App\Services\AttemptConverter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Support\Facades\Cache;

#[FailOnTimeout]
class ProcessConversionQueueJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function handle(AttemptConverter $converter): void
    {
        $lock = Cache::lock('conversion-processing', $this->timeout);

        if (! $lock->get()) {
            return;
        }

        try {
            while ($attempt = $this->nextPendingAttempt()) {
                $converter->convert($attempt);
            }
        } finally {
            $lock->release();
        }
    }

    private function nextPendingAttempt(): ?Attempt
    {
        return Attempt::query()
            ->where('status', AttemptStatus::Pending->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }
}
