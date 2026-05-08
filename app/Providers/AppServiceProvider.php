<?php

namespace App\Providers;

use App\AttemptStatus;
use App\FailureCode;
use App\Models\Attempt;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinutes(15, 5)
            ->by('uploads:'.$request->ip())
            ->response(fn (): mixed => response('Too many uploads. Please wait a few minutes.', 429)));

        RateLimiter::for('conversion-reads', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('conversion-reads:'.$request->ip()));

        Queue::looping(function (): void {
            Attempt::query()
                ->where('status', AttemptStatus::Running->value)
                ->where('heartbeat_at', '<', now()->subSeconds((int) config('conversion.timeout')))
                ->update([
                    'status' => AttemptStatus::Failed->value,
                    'failure_code' => FailureCode::Interrupted->value,
                    'failure_message' => FailureCode::Interrupted->message(),
                    'finished_at' => now(),
                ]);
        });
    }
}
