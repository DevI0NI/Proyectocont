<?php

namespace App\Providers;

use App\Models\Bloque;
use App\Models\EstatusContenedor;
use App\Models\Modelo;
use App\Models\Patio;
use App\Models\Sucursal;
use App\Models\TipoUnidad;
use App\Observers\SystemActivityObserver;
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
        foreach ([Sucursal::class, Patio::class, Bloque::class, TipoUnidad::class, Modelo::class, EstatusContenedor::class] as $model) {
            $model::observe(SystemActivityObserver::class);
        }
    }
}
