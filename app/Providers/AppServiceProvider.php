<?php

namespace App\Providers;

use App\Contracts\TelephonyClient;
use App\Services\Telephony\LoggingTelephonyClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TelephonyClient::class, LoggingTelephonyClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
