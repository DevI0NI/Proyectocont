<?php

use App\Models\User;
use App\Services\RentalParkingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? '') === 'cleanup') {
    $location = (int) ($argv[2] ?? 0);
    $record = DB::table('rental_locations')->where('id', $location)->first();
    if ($record && str_starts_with($record->name, 'QA-PARKING-')) {
        DB::transaction(function () use ($location): void {
            DB::table('rental_events')->where('location_id', $location)->delete();
            DB::table('rental_stays')->where('location_id', $location)->delete();
            DB::table('rental_rates')->where('location_id', $location)->delete();
            DB::table('rental_spaces')->where('location_id', $location)->delete();
            DB::table('rental_locations')->where('id', $location)->delete();
        });
    }
    if (isset($argv[3])) {
        app('session')->driver()->getHandler()->destroy($argv[3]);
    }
    exit;
}

$user = User::where('rol_id', 1)->where('estado_id', 1)->firstOrFail();
$location = DB::table('rental_locations')->insertGetId(['name' => 'QA-PARKING-'.uniqid(), 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
DB::table('rental_rates')->insert(['location_id' => $location, 'hourly_cents' => 1000, 'daily_cents' => 6000, 'created_by' => $user->id, 'created_at' => now()]);
for ($i = 1; $i <= 12; $i++) {
    $space = DB::table('rental_spaces')->insertGetId(['location_id' => $location, 'code' => 'QA-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'status' => $i === 12 ? 'maintenance' : 'available', 'created_at' => now(), 'updated_at' => now()]);
    if ($i <= 2) {
        app(RentalParkingService::class)->enter(['space_id' => $space, 'plate' => 'QA'.$location.'T'.$i, 'driver' => 'Conductor QA '.$i, 'billing_unit' => 'hour'], $user->id);
    }
}
$session = app('session')->driver();
$session->start();
$session->put(Auth::guard('web')->getName(), $user->id);
$session->put('sucursal_activa', $user->sucursal_id ?: DB::table('sucursales')->where('estado_id', 1)->value('id'));
$session->save();
$name = config('session.cookie');
$value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(), false);
echo json_encode(['location' => $location, 'session_id' => $session->getId(), 'cookie_name' => $name, 'cookie_value' => $value], JSON_THROW_ON_ERROR);
