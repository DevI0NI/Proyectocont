<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentalParkingService
{
    public function cents(string $amount): int
    {
        $parts = explode('.', $amount);

        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public function quote(object $stay, ?CarbonImmutable $end = null): array
    {
        $end ??= $stay->exited_at ? CarbonImmutable::parse($stay->exited_at, 'UTC') : CarbonImmutable::now('UTC');
        $start = CarbonImmutable::parse($stay->entered_at, 'UTC');
        $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());
        $period = $stay->billing_unit === 'hour' ? 3600 : 86400;
        $units = max(1, (int) ceil($seconds / $period));

        return ['seconds' => $seconds, 'units' => $units, 'total_cents' => $units * (int) $stay->rate_cents, 'at' => $end->toDateTimeString()];
    }

    public function enter(array $data, int $userId): int
    {
        return DB::transaction(function () use ($data, $userId): int {
            $space = DB::table('rental_spaces')->where('id', $data['space_id'])->lockForUpdate()->first();
            if (! $space || $space->status !== 'available' || DB::table('rental_stays')->where('active_space_id', $space->id)->exists()) {
                throw ValidationException::withMessages(['space_id' => 'El espacio ya no esta disponible. Selecciona otro.']);
            }
            if (DB::table('rental_stays')->where('active_plate', $data['plate'])->exists()) {
                throw ValidationException::withMessages(['plate' => 'Esta placa ya tiene una estadia activa.']);
            }
            $rate = DB::table('rental_rates')->where('location_id', $space->location_id)->orderByDesc('id')->first();
            if (! $rate) {
                throw ValidationException::withMessages(['space_id' => 'Configura las tarifas de este predio antes de registrar entradas.']);
            }
            $location = DB::table('rental_locations')->find($space->location_id);
            $id = DB::table('rental_stays')->insertGetId([
                'location_id' => $space->location_id,
                'space_id' => $space->id,
                'rate_id' => $rate->id,
                'plate' => $data['plate'],
                'active_plate' => $data['plate'],
                'active_space_id' => $space->id,
                'driver' => $data['driver'],
                'customer' => $data['customer'] ?? null,
                'billing_unit' => $data['billing_unit'],
                'rate_cents' => $data['billing_unit'] === 'hour' ? $rate->hourly_cents : $rate->daily_cents,
                'currency' => $location->currency,
                'entered_at' => CarbonImmutable::now('UTC')->toDateTimeString(),
                'entered_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->event($space->location_id, $id, $userId, 'Entrada registrada', ['placa' => $data['plate'], 'espacio' => $space->code, 'tarifa_id' => $rate->id]);

            return $id;
        }, 3);
    }

    public function settle(int $stayId, array $data, int $userId): void
    {
        DB::transaction(function () use ($stayId, $data, $userId): void {
            $stay = DB::table('rental_stays')->where('id', $stayId)->lockForUpdate()->first();
            abort_unless($stay, 404);
            if ($stay->exited_at !== null) {
                throw ValidationException::withMessages(['settlement' => 'Esta estadia ya fue liquidada.']);
            }
            $quote = $this->quote($stay);
            if ((int) $data['expected_total_cents'] !== $quote['total_cents']) {
                throw ValidationException::withMessages(['settlement' => 'La estadia paso a otro periodo de cobro. Revisa el importe actualizado antes de confirmar.']);
            }
            DB::table('rental_stays')->where('id', $stayId)->update([
                'exited_at' => $quote['at'],
                'billable_units' => $quote['units'],
                'total_cents' => $quote['total_cents'],
                'payment_method' => $data['payment_method'],
                'payment_reference' => $data['payment_reference'] ?? null,
                'closed_by' => $userId,
                'active_plate' => null,
                'active_space_id' => null,
            ]);
            $this->event($stay->location_id, $stayId, $userId, 'Liquidacion y salida', [
                'total_centavos' => $quote['total_cents'], 'moneda' => $stay->currency,
                'periodos' => $quote['units'], 'medio' => $data['payment_method'],
            ]);
        }, 3);
    }

    public function event(int $locationId, ?int $stayId, int $userId, string $action, array $details): void
    {
        DB::table('rental_events')->insert([
            'location_id' => $locationId, 'stay_id' => $stayId, 'user_id' => $userId,
            'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => CarbonImmutable::now('UTC')->toDateTimeString(),
        ]);
    }
}
