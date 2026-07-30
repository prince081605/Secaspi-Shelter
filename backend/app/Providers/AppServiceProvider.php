<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\SimulatedGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The swap point for a real payment provider: implement PaymentGateway,
        // add it to this map, set PAYMENTS_DRIVER. Nothing else in the app knows
        // which gateway it is talking to.
        $this->app->singleton(PaymentGateway::class, function () {
            return match (config('payments.driver')) {
                default => new SimulatedGateway(),
            };
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
