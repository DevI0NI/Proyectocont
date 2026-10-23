<?php

use App\Services\SystemNotifications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:sync-pending', function () {
    DB::table('maintenance_review_requests')->where('status', 'pending')->orderBy('id')
        ->eachById(function ($review): void {
            app(SystemNotifications::class)->reviewRequested((int) $review->id);
        });
    $this->info('Solicitudes pendientes incorporadas a las notificaciones, sin duplicados.');
})->purpose('Incorporar solicitudes pendientes a las bandejas autorizadas');

Artisan::command('notifications:prune', function () {
    $this->info(app(SystemNotifications::class)->pruneExpired().' notificaciones archivadas vencidas eliminadas.');
})->purpose('Eliminar notificaciones que cumplieron un mes en Archivados');

Schedule::command('notifications:prune')->hourly()->withoutOverlapping();
