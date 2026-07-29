<?php

namespace App\Providers;

use App\Models\InventoryMovement;
use App\Observers\InventoryMovementObserver;
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
        InventoryMovement::observe(InventoryMovementObserver::class);
    }
}
