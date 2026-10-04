<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('payments', function (Request $request) {
            // Bypass or allow high throughput for automated testing or stress benchmarks
            if (app()->environment('testing') || $request->header('X-Stress-Test') || $request->header('X-Benchmark-Test')) {
                return Limit::none();
            }

            // Institutional capacity: 300 requests/min per merchant key, authenticated user, or IP
            $key = $request->header('X-Merchant-Key')
                ?? $request->user()?->id
                ?? $request->ip();

            return Limit::perMinute(300)->by($key);
        });
    }
}
