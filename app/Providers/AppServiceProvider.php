<?php

namespace App\Providers;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Services\ServicioTrabajoWebPushService;
use Illuminate\Support\Facades\Event;
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
        Event::listen(OrdenCocinaActualizadaEvent::class, function (OrdenCocinaActualizadaEvent $event): void {
            if (app()->runningUnitTests()) return;
            app()->terminating(fn () => app(ServicioTrabajoWebPushService::class)->syncForOrder($event->ordenId));
        });
    }
}
