<?php

namespace App\Providers;

use App\Models\SyncState;
use App\Policies\SyncStatePolicy;
use Illuminate\Support\Facades\Gate;
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
        // Register the SyncStatePolicy
        Gate::policy(SyncState::class, SyncStatePolicy::class);
    }
}
