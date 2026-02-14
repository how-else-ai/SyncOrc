<?php

namespace App\Providers;

use App\Models\Device;
use App\Observers\DeviceObserver;
use Illuminate\Support\ServiceProvider;

class ObserverServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Device::observe(DeviceObserver::class);
    }
}
