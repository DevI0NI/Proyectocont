<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RentalParkingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RentalParkingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('sucursales', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nombre');
            $table->integer('estado_id');
        });
        DB::table('sucursales')->insert(['id' => 1, 'nombre' => 'Sucursal QA', 'estado_id' => 1]);
        $this->withSession(['sucursal_activa' => 1]);
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('email');
            $table->integer('rol_id');
            $table->integer('estado_id');
            $table->integer('sucursal_id')->nullable();
        });
        DB::table('users')->insert(['id' => 77, 'username' => 'QA', 'email' => 'qa@example.test', 'rol_id' => 1, 'estado_id' => 1]);
        $this->createNotificationTables();
        (require database_path('migrations/2026_10_06_151750_create_rental_parking_tables.php'))->up();
    }

    public function test_only_superuser_can_access_every_parking_endpoint(): void
    {
        $this->get('/rentas')->assertRedirect('/login');
        foreach ([2, 3, 4, 5] as $role) {
            $this->actingAs($this->user($role));
            foreach (['/rentas', '/rentas/registros', '/rentas/configuracion', '/rentas/configuracion/1', '/rentas/espacios', '/rentas/estadias/1'] as $path) {
                $this->get($path)->assertForbidden();
            }
            foreach (['/rentas/predios', '/rentas/estadias', '/rentas/predios/1/tarifas', '/rentas/predios/1/espacios', '/rentas/estadias/1/liquidar'] as $path) {
                $this->post($path, [])->assertForbidden();
            }
            $this->patch('/rentas/espacios/1', ['status' => 'available'])->assertForbidden();
            $this->delete('/rentas/espacios/1')->assertForbidden();
        }
        $this->actingAs($this->user())->get('/rentas')->assertOk()->assertSee('Configura tu primer predio');
    }

    public function test_configuration_uses_independent_locations_and_records_events(): void
    {
        $this->actingAs($this->user())->get('/rentas/configuracion')->assertOk()->assertSee('Todavia no hay predios')->assertSee('data-dialog-open="parking-location-dialog"', false);
        $this->from('/rentas/configuracion')->followingRedirects()->post('/rentas/predios', ['name' => '', 'currency' => 'GTQ'])->assertOk()->assertSee('data-open-on-load', false);
        $response = $this->post('/rentas/predios', ['name' => 'Predio independiente', 'currency' => 'GTQ'])->assertSessionHasNoErrors();
        $id = DB::table('rental_locations')->value('id');
        $response->assertRedirect(route('rentals.settings.location', $id));
        $this->get('/rentas/configuracion/999')->assertNotFound();
        $this->post("/rentas/predios/$id/espacios", ['prefix' => 'A', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post("/rentas/predios/$id/espacios", ['prefix' => 'A', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_spaces', ['code' => 'A-03']);
        $this->post("/rentas/predios/$id/tarifas", ['hourly' => '12.50', 'daily' => '100.01'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_rates', ['hourly_cents' => 1250, 'daily_cents' => 10001]);
        $this->get('/rentas?location='.$id)->assertOk()->assertSee('Predio independiente');
        $this->get('/rentas/configuracion')->assertOk()->assertSee('Predio independiente')->assertSee(route('rentals.settings.location', $id), false)->assertSee('GTQ 12.50 / hora')->assertDontSee('data-open-on-load', false);
        $this->get('/rentas/configuracion/'.$id)->assertOk()->assertSee('Predio independiente')->assertSee('A-03')->assertSee('value="12.50"', false)->assertSee('Bloquear');
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
        $this->actingAs($this->user())->post('/rentas/estadias', [...$this->entry($space), 'plate' => 'c-123 abc', 'rate_cents' => 1, 'entered_at' => '2000-01-01'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('rentals.index', ['location' => $location]))
            ->assertSessionHas('status', 'Entrada registrada: C123ABC en el espacio A-01.');
        $stay = DB::table('rental_stays')->first();
        $this->assertSame('C123ABC', $stay->plate);
        $this->assertSame(1000, $stay->rate_cents);
        $this->get('/rentas?location='.$location)->assertOk()
            ->assertSee('parking-tile parking-space-option occupied', false)
            ->assertSee('C123ABC')
            ->assertSee('Desde 06/10 06:00')
            ->assertSee(route('rentals.show', $stay->id), false)
            ->assertSee('data-live-since="0"', false);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:30:00', 'UTC'));
        $this->get('/rentas/estadias/'.$stay->id)->assertOk()
            ->assertSee('Total a cobrar ahora')
            ->assertSee('data-seconds="1800"', false)
            ->assertSee('se cobra en')
            ->assertSee('0 h 30 min')
            ->assertSee('06/10/2026 06:00 · QA');
        $this->post("/rentas/predios/$location/tarifas", ['hourly' => '99', 'daily' => '999'])->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 13:10:00', 'UTC'));
        $data = ['expected_total_cents' => 2000, 'payment_method' => 'cash'];
        $this->post("/rentas/estadias/$stay->id/liquidar", $data)->assertSessionHasErrors('payment_confirmed');
        $this->post("/rentas/estadias/$stay->id/liquidar", [...$data, 'payment_method' => 'transfer', 'payment_confirmed' => 1])->assertSessionHasErrors('payment_reference');
        $back = '/rentas/registros?location='.$location;
        $this->get('/rentas/estadias/'.$stay->id.'?return_to='.urlencode($back))->assertOk()->assertSee('Volver a registros')->assertSee('href="'.e($back).'"', false);
        $this->get('/rentas/estadias/'.$stay->id.'?return_to='.urlencode('https://evil.example/rentas/registros'))->assertOk()->assertSee('Volver al plano')->assertDontSee('evil.example');
        $this->post("/rentas/estadias/$stay->id/liquidar", [...$data, 'payment_confirmed' => 1, 'return_to' => $back])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('rentals.show', ['stay' => $stay->id, 'return_to' => $back]));
        $this->assertDatabaseHas('rental_stays', ['id' => $stay->id, 'total_cents' => 2000, 'billable_units' => 2, 'active_space_id' => null, 'active_plate' => null, 'closed_by' => 77]);
        $this->get('/rentas/estadias/'.$stay->id)->assertOk()->assertSee('Total liquidado')->assertSee('20.00');
        $this->post("/rentas/estadias/$stay->id/liquidar", [...$data, 'payment_confirmed' => 1])->assertSessionHasErrors('settlement');
        $this->post('/rentas/estadias', $this->entry($space))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('rental_stays', 2);
        $this->get('/rentas?location='.$location)->assertOk()->assertDontSee('parking-register', false)->assertSee(route('rentals.records', ['location' => $location]), false);
        $this->get('/rentas/registros?location='.$location)->assertOk()->assertSee('Dentro del predio')->assertSee('C123ABC')->assertSee('Cobrar salida')->assertSee('containers-table', false)->assertSee('Pagina 1 de 1');
        $this->get('/rentas/registros?location='.$location.'&tab=history')->assertOk()->assertSee('Cobradas')->assertSee('Total cobrado')->assertSee('Comprobante')->assertSee('GTQ 20.00');
        $this->get('/rentas/registros?location='.$location.'&tab=history&q=NOEXISTE')->assertOk()->assertSee('No hay estadias cobradas para estos filtros.');
        $this->get('/rentas/registros?location='.$location.'&tab=history&filters[mode]=day')->assertOk()->assertDontSee('Comprobante');
        $this->get('/rentas/registros?location='.$location.'&tab=history&filters[space]=A-01&sort=plate&order=asc')->assertOk()->assertSee('Comprobante');
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
        $this->post('/rentas/estadias', [...$this->entry($space), 'notes' => '<script>alert(1)</script>'])->assertSessionHasNoErrors();
        $stay = DB::table('rental_stays')->first();
        $this->get('/rentas/estadias/'.$stay->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_entry_uses_the_system_plate_and_name_rules(): void
    {
        [, $space] = $this->fixture();
        $this->actingAs($this->user());
        foreach (['ABC', 'ABCDEFG', 'C1234567890', 'C12#ABC'] as $plate) {
            $this->post('/rentas/estadias', [...$this->entry($space), 'plate' => $plate])->assertSessionHasErrors('plate');
        }
        foreach (['Juan 123', '<script>', str_repeat('a', 81)] as $driver) {
            $this->post('/rentas/estadias', [...$this->entry($space), 'driver' => $driver])->assertSessionHasErrors('driver');
        }
        $this->post('/rentas/estadias', [...$this->entry($space), 'customer' => str_repeat('Transportes ', 9)])->assertSessionHasErrors('customer');
        $this->post('/rentas/estadias', [...$this->entry($space), 'notes' => str_repeat('a', 501)])->assertSessionHasErrors('notes');
        $this->assertDatabaseCount('rental_stays', 0);
        $this->post('/rentas/estadias', [...$this->entry($space), 'plate' => 'tc-12 abc', 'driver' => 'José Pérez', 'customer' => 'Transportes Izabal, S.A.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_stays', ['plate' => 'TC12ABC', 'driver' => 'José Pérez']);
    }

    public function test_only_the_last_space_of_a_row_can_be_removed_without_losing_history(): void
    {
        [$location, $first] = $this->fixture();
        $this->actingAs($this->user())->post("/rentas/predios/$location/espacios", ['prefix' => 'A', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post("/rentas/predios/$location/espacios", ['prefix' => 'B', 'quantity' => 1])->assertSessionHasNoErrors();
        [$second, $third, $otherRow] = ['A-02', 'A-03', 'B-01'];
        $ids = DB::table('rental_spaces')->pluck('id', 'code');
        $this->get('/rentas/configuracion/'.$location)->assertOk()->assertSee('ya tiene <strong>4 espacios</strong>', false)->assertDontSee('value="10"', false)
            ->assertSee('(A-03, B-01)')->assertSee('data-url="'.route('rentals.spaces.destroy', $ids[$third]).'"', false)->assertSee('data-url="'.route('rentals.spaces.destroy', $ids[$otherRow]).'"', false)
            ->assertDontSee('data-url="'.route('rentals.spaces.destroy', $ids[$second]).'"', false);

        $this->delete('/rentas/espacios/'.$ids[$second])->assertSessionHasErrors(['space' => 'Solo puedes quitar el ultimo espacio de la fila A (A-03), para que no queden huecos. Si A-02 no se puede usar, bloquealo.']);
        $this->post('/rentas/estadias', $this->entry($first))->assertSessionHasNoErrors();
        $this->delete('/rentas/espacios/'.$ids[$third])->assertSessionHasNoErrors()->assertSessionHas('status', 'Espacio A-03 quitado del predio.');
        $this->delete('/rentas/espacios/'.$ids[$second])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('rental_spaces', ['code' => 'A-02']);
        $this->delete('/rentas/espacios/'.$first)->assertSessionHasErrors('space');
        $stay = DB::table('rental_stays')->first();
        $this->post("/rentas/estadias/$stay->id/liquidar", ['expected_total_cents' => 1000, 'payment_method' => 'cash', 'payment_confirmed' => 1])->assertSessionHasNoErrors();
        $this->delete('/rentas/espacios/'.$first)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_spaces', ['id' => $first, 'status' => 'retired']);
        $this->delete('/rentas/espacios/'.$first)->assertNotFound();
        $this->patch('/rentas/espacios/'.$first, ['status' => 'maintenance'])->assertNotFound();
        $this->post('/rentas/estadias', [...$this->entry($first), 'plate' => 'C555XYZ'])->assertSessionHasErrors('space_id');

        $this->get('/rentas?location='.$location)->assertOk()->assertDontSee('data-space-code="A-01"', false)->assertSee('data-space-code="B-01"', false);
        $this->get('/rentas/estadias/'.$stay->id)->assertOk()->assertSee('Espacio A-01');
        $this->get('/rentas/registros?location='.$location.'&tab=history')->assertOk()->assertSee('A-01');

        $this->post("/rentas/predios/$location/espacios", ['prefix' => 'A', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_spaces', ['id' => $first, 'status' => 'available']);
        $this->assertDatabaseHas('rental_spaces', ['code' => 'A-02', 'status' => 'available']);
        $this->assertDatabaseCount('rental_spaces', 3);
    }

    public function test_space_settings_are_paginated_by_24(): void
    {
        [$location] = $this->fixture();
        $this->actingAs($this->user())->post("/rentas/predios/$location/espacios", ['prefix' => 'A', 'quantity' => 29])->assertSessionHasNoErrors();
        $this->get('/rentas/configuracion/'.$location)->assertOk()->assertSee('A-24')->assertDontSee('A-25')->assertSee('Pagina 1 de 2 · 30 espacios')->assertSee('Libre · 30');
        $this->get('/rentas/configuracion/'.$location.'?page=2')->assertOk()->assertSee('A-30')->assertDontSee('>A-01<', false);
    }

    public function test_spaces_follow_their_code_order_after_being_added_back(): void
    {
        [$location] = $this->fixture();
        $this->actingAs($this->user())->post("/rentas/predios/$location/espacios", ['prefix' => 'A', 'quantity' => 10])->assertSessionHasNoErrors();
        DB::table('rental_spaces')->where('code', 'A-05')->delete();
        $this->post("/rentas/predios/$location/espacios", ['prefix' => 'A', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->assertGreaterThan(DB::table('rental_spaces')->where('code', 'A-11')->value('id'), DB::table('rental_spaces')->where('code', 'A-05')->value('id'));
        $this->get('/rentas?location='.$location)->assertOk()->assertSeeInOrder(['data-space-code="A-04"', 'data-space-code="A-05"', 'data-space-code="A-06"', 'data-space-code="A-11"'], false);
        $this->get('/rentas/configuracion/'.$location)->assertOk()->assertSeeInOrder(['>A-04<', '>A-05<', '>A-06<', '>A-11<'], false);
    }

    public function test_settings_reject_invalid_rates_rows_and_location_data(): void
    {
        [$location] = $this->fixture();
        $this->actingAs($this->user());
        foreach (['10000000', '12.345', 'abc', '-5', ''] as $amount) {
            $this->post("/rentas/predios/$location/tarifas", ['hourly' => $amount, 'daily' => '100'])->assertSessionHasErrors('hourly');
        }
        $this->post("/rentas/predios/$location/tarifas", ['hourly' => '50', 'daily' => '20'])->assertSessionHasErrors(['daily' => 'La tarifa por dia no puede ser menor que la tarifa por hora.']);
        $this->post("/rentas/predios/$location/tarifas", ['hourly' => '999999.99', 'daily' => '999999.99'])->assertSessionHasNoErrors();

        foreach (['A1', 'ABC', '1', '-'] as $prefix) {
            $this->post("/rentas/predios/$location/espacios", ['prefix' => $prefix, 'quantity' => 1])->assertSessionHasErrors('prefix');
        }
        foreach (['0', '101', '2.5', 'diez'] as $quantity) {
            $this->post("/rentas/predios/$location/espacios", ['prefix' => 'B', 'quantity' => $quantity])->assertSessionHasErrors('quantity');
        }
        $this->post("/rentas/predios/$location/espacios", ['prefix' => 'b', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_spaces', ['code' => 'B-02']);
        foreach (range(1, 4) as $round) {
            $this->post("/rentas/predios/$location/espacios", ['prefix' => 'C', 'quantity' => 100])->assertSessionHasNoErrors();
        }
        $this->post("/rentas/predios/$location/espacios", ['prefix' => 'D', 'quantity' => 100])->assertSessionHasErrors(['quantity' => 'Un predio puede tener maximo 500 espacios. Puedes sumar 97 mas.']);

        foreach (['', 'AB', '12345', '<script>', str_repeat('Predio ', 20)] as $name) {
            $this->post('/rentas/predios', ['name' => $name, 'currency' => 'GTQ'])->assertSessionHasErrors('name');
        }
        $this->post('/rentas/predios', ['name' => 'Parqueo QA', 'currency' => 'GTQ'])->assertSessionHasErrors(['name' => 'Ya existe un predio con ese nombre.']);
        foreach (['1234', '12345 67', 'Calle <b>', str_repeat('Calle ', 40)] as $address) {
            $this->post('/rentas/predios', ['name' => 'Predio Norte', 'address' => $address, 'currency' => 'GTQ'])->assertSessionHasErrors('address');
        }
        $this->post('/rentas/predios', ['name' => 'Predio Norte', 'currency' => 'EUR'])->assertSessionHasErrors('currency');
        $this->post('/rentas/predios', ['name' => '  Predio Norte  ', 'address' => '15 calle #4-20, zona 1', 'currency' => 'USD'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_locations', ['name' => 'Predio Norte', 'address' => '15 calle #4-20, zona 1']);
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
