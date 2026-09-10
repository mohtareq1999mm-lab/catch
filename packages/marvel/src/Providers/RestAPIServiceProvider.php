<?php

namespace Marvel\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;


class RestApiServiceProvider extends ServiceProvider
{

    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadRoutes();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/shop.php', 'shop');
    }

    public function loadRoutes(): void
    {
        Route::prefix('api/v1')
            ->middleware('api')
            ->group(__DIR__ . '/../Rest/Routes.php');
    }
}
