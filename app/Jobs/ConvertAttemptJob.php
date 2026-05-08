<?php

namespace App\Jobs;

use App\Models\Attempt;
use App\Services\AttemptConverter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\FailOnTimeout;

#[DeleteWhenMissingModels]
#[FailOnTimeout]
class ConvertAttemptJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $attemptId)
    {
        //
    }

    public function handle(AttemptConverter $converter): void
    {
        $attempt = Attempt::query()->findOrFail($this->attemptId);
        $converter->convert($attempt);
    }
}
