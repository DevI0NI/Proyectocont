<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RentalParkingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RentalParkingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_06_151750_create_rental_parking_tables.php'))->up();
    }

    public function test_only_superuser_can_access_every_parking_endpoint(): void
    {
        $this->get('/rentas')->assertRedirect('/login');
        foreach ([2, 3, 4, 5] as $role) {
            $this->actingAs($this->user($role));
            foreach (['/rentas', '/rentas/configuracion', '/rentas/espacios', '/rentas/estadias/1'] as $path) {
                $this->get($path)->assertForbidden();
            }
            foreach (['/rentas/predios', '/rentas/estadias', '/rentas/predios/1/tarifas', '/rentas/predios/1/espacios', '/rentas/estadias/1/liquidar'] as $path) {
                $this->post($path, [])->assertForbidden();
            }
            $this->patch('/rentas/espacios/1', ['status' => 'available'])->assertForbidden();
        }
        $this->actingAs($this->user())->get('/rentas')->assertOk()->assertSee('Configura tu primer predio');
    }

    public function test_configuration_uses_independent_locations_and_records_events(): void
    {
        $this->actingAs($this->user())->post('/rentas/predios', ['name' => 'Predio independiente', 'currency' => 'GTQ'])->assertSessionHasNoErrors();
        $id = DB::table('rental_locations')->value('id');
        $this->post("/rentas/predios/$id/espacios", ['prefix' => 'A', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post("/rentas/predios/$id/espacios", ['prefix' => 'A', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_spaces', ['code' => 'A-03']);
        $this->post("/rentas/predios/$id/tarifas", ['hourly' => '12.50', 'daily' => '100.01'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_rates', ['hourly_cents' => 1250, 'daily_cents' => 10001]);
        $this->get('/rentas?location='.$id)->assertOk()->assertSee('Predio independiente');
        $this->get('/rentas/configuracion')->assertOk();
        $this->assertDatabaseCount('rental_events', 4);
    }

    public function test_entry_rejects_missing_tariff_occupied_space_duplicate_plate_and_blocked_space(): void
    {
        [$location, $space] = $this->fixture(false);
        $this->actingAs($this->user())->post('/rentas/estadias', $this->entry($space))->assertSessionHasErrors('space_id');
        $this->post("/rentas/predios/$location/tarifas", ['hourly' => '10', 'daily' => '60'])->assertSessionHasNoErrors();
        $this->post('/rentas/estadias', $this->entry($space))->assertSessionHasNoErrors();
        $this->post('/rentas/estadias', [...$this->entry($space), 'plate' => 'C999XYZ'])->assertSessionHasErrors('space_id');
        $other = DB::table('rental_spaces')->insertGetId(['location_id' => $location, 'code' => 'B-01', 'status' => 'available']);
        $this->post('/rentas/estadias', $this->entry($other))->assertSessionHasErrors('plate');
        $this->patch('/rentas/espacios/'.$space, ['status' => 'maintenance'])->assertSessionHasErrors('space');
        $this->patch('/rentas/espacios/'.$other, ['status' => 'maintenance'])->assertSessionHasNoErrors();
        $this->post('/rentas/estadias', [...$this->entry($other), 'plate' => 'C999XYZ'])->assertSessionHasErrors('space_id');
        $this->assertDatabaseCount('rental_stays', 1);
    }

    public function test_hourly_settlement_keeps_entry_rate_requires_payment_and_releases_space_atomically(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
        [$location, $space] = $this->fixture();
        $this->actingAs($this->user())->post('/rentas/estadias', [...$this->entry($space), 'plate' => 'c-123 abc', 'rate_cents' => 1, 'entered_at' => '2000-01-01'])->assertSessionHasNoErrors();
        $stay = DB::table('rental_stays')->first();
        $this->assertSame('C123ABC', $stay->plate);
        $this->assertSame(1000, $stay->rate_cents);
        $this->post("/rentas/predios/$location/tarifas", ['hourly' => '99', 'daily' => '999'])->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 13:10:00', 'UTC'));
        $data = ['expected_total_cents' => 2000, 'payment_method' => 'cash'];
        $this->post("/rentas/estadias/$stay->id/liquidar", $data)->assertSessionHasErrors('payment_confirmed');
        $this->post("/rentas/estadias/$stay->id/liquidar", [...$data, 'payment_method' => 'transfer', 'payment_confirmed' => 1])->assertSessionHasErrors('payment_reference');
        $this->post("/rentas/estadias/$stay->id/liquidar", [...$data, 'payment_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_stays', ['id' => $stay->id, 'total_cents' => 2000, 'billable_units' => 2, 'active_space_id' => null, 'active_plate' => null, 'closed_by' => 77]);
        $this->get('/rentas/estadias/'.$stay->id)->assertOk()->assertSee('Total liquidado')->assertSee('20.00');
        $this->post("/rentas/estadias/$stay->id/liquidar", [...$data, 'payment_confirmed' => 1])->assertSessionHasErrors('settlement');
        $this->post('/rentas/estadias', $this->entry($space))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('rental_stays', 2);
        $this->get('/rentas?location='.$location.'&tab=history')->assertOk()->assertSee('Comprobante');
        $this->travelBack();
    }

    public function test_daily_periods_use_24_hours_not_calendar_dates_and_stale_quote_does_not_close(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 23:55:00', 'UTC'));
        [, $space] = $this->fixture();
        $this->actingAs($this->user())->post('/rentas/estadias', [...$this->entry($space), 'billing_unit' => 'day'])->assertSessionHasNoErrors();
        $stay = DB::table('rental_stays')->first();
        $service = app(RentalParkingService::class);
        $this->assertSame(1, $service->quote($stay, CarbonImmutable::parse('2026-10-07 00:10:00', 'UTC'))['units']);
        $this->assertSame(1, $service->quote($stay, CarbonImmutable::parse('2026-10-07 23:55:00', 'UTC'))['units']);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 23:55:01', 'UTC'));
        $this->post("/rentas/estadias/$stay->id/liquidar", ['expected_total_cents' => 6000, 'payment_method' => 'cash', 'payment_confirmed' => 1])->assertSessionHasErrors('settlement');
        $this->assertDatabaseHas('rental_stays', ['id' => $stay->id, 'exited_at' => null, 'active_space_id' => $space]);
        $this->assertSame(12000, $service->quote($stay)['total_cents']);
        $this->travelBack();
    }

    public function test_invalid_inputs_are_rejected_and_free_text_is_escaped(): void
    {
        [$location, $space] = $this->fixture();
        $this->actingAs($this->user())->post('/rentas/estadias', [])->assertSessionHasErrors(['space_id', 'plate', 'driver', 'billing_unit']);
        $this->post("/rentas/predios/$location/tarifas", ['hourly' => '0', 'daily' => '10'])->assertSessionHasErrors('hourly');
        $this->post('/rentas/estadias', [...$this->entry($space), 'billing_unit' => 'month'])->assertSessionHasErrors('billing_unit');
        $this->post('/rentas/estadias', [...$this->entry($space), 'driver' => '<script>alert(1)</script>'])->assertSessionHasNoErrors();
        $stay = DB::table('rental_stays')->first();
        $this->get('/rentas/estadias/'.$stay->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    private function fixture(bool $withRate = true): array
    {
        $location = DB::table('rental_locations')->insertGetId(['name' => 'Parqueo QA', 'currency' => 'GTQ']);
        $space = DB::table('rental_spaces')->insertGetId(['location_id' => $location, 'code' => 'A-01', 'status' => 'available']);
        if ($withRate) {
            DB::table('rental_rates')->insert(['location_id' => $location, 'hourly_cents' => 1000, 'daily_cents' => 6000, 'created_by' => 77, 'created_at' => now()]);
        }

        return [$location, $space];
    }

    private function entry(int $space): array
    {
        return ['space_id' => $space, 'plate' => 'C123ABC', 'driver' => 'Conductor QA', 'billing_unit' => 'hour'];
    }

    private function user(int $role = 1): User
    {
        $user = new User(['username' => 'QA', 'email' => 'qa@example.test', 'rol_id' => $role, 'estado_id' => 1]);
        $user->id = 77;

        return $user;
    }
}
