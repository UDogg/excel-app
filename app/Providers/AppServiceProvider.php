<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\FakeDataService;
use App\Services\ExcelSpecService;
use App\Services\EndorsementPreValidator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FakeDataService::class, function ($app) {
            // Optional: Pass a seed for reproducible fake data in tests
            // $seed = config('app.fake_data_seed');
            //  $seed = null; // No seed for true randomness in production
            //  $seed = 12345; // Fixed seed for consistent data during development/testing
            //  $seed = env('FAKE_DATA_SEED', null); // Use env variable for flexibility
            return new FakeDataService(null);
        });

        // ExcelSpecService - Singleton (no state, safe to share)
        $this->app->singleton(ExcelSpecService::class, function ($app) {
            return new ExcelSpecService();
        });

        // EndorsementPreValidator - Singleton (validation logic, no state)
        $this->app->singleton(EndorsementPreValidator::class, function ($app) {
            return new EndorsementPreValidator();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
