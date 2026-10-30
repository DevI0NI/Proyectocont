<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GuatemalaGeography;
use App\Services\IsoCodeCatalog;
use App\Services\OperationalCatalog;
use Database\Seeders\IsoCodeCatalogSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationalCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('sucursales', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nombre');
            $table->string('ubicacion')->nullable();
            $table->integer('estado_id')->default(1);
        });
        (require database_path('migrations/2026_10_12_174048_add_geography_to_sucursales_table.php'))->up();
        DB::table('sucursales')->insert([
            ['id' => 1, 'nombre' => 'Sucursal A', 'estado_id' => 1],
            ['id' => 2, 'nombre' => 'Sucursal B', 'estado_id' => 1],
            ['id' => 3, 'nombre' => 'Sucursal inactiva', 'estado_id' => 2],
        ]);
        (require database_path('migrations/2026_10_11_233137_create_operational_catalog_tables.php'))->up();
        (require database_path('migrations/2026_10_13_202712_create_shipping_line_branch_auditor_table.php'))->up();
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username')->nullable();
            $table->integer('rol_id')->default(2);
            $table->integer('sucursal_id');
            $table->integer('estado_id');
        });
        $this->createNotificationTables();
        $this->withSession(['sucursal_activa' => 1]);
    }

    public function test_validation_errors_are_descriptions_in_spanish_not_translation_keys(): void
    {
        app()->setLocale('es');
        $this->actingAs($this->user());
        $this->postJson('/catalogos/navieras', ['branches' => [1]])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Escribe el nombre del registro.')
            ->assertJsonPath('errors.name_key.0', 'Escribe el nombre del registro.')->assertDontSee('validation.');
        $this->postJson('/catalogos/repuestos', ['name' => 'Bisagra', 'currency' => 'GTQ'])->assertUnprocessable()
            ->assertJsonPath('errors.unit.0', 'Completa el campo unidad.')->assertDontSee('validation.');
        $this->postJson('/catalogos/auditoras', ['name' => str_repeat('a', 121), 'email' => 'incorrecto'])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'El campo nombre admite como maximo 120 caracteres.')
            ->assertJsonPath('errors.email.0', 'Ingresa un correo electronico valido.')->assertDontSee('validation.');
    }

    public function test_catalogs_require_administrative_roles_and_known_catalog_names(): void
    {
        $this->get('/catalogos/navieras')->assertRedirect('/login');
        foreach ([3, 4, 5] as $role) {
            $this->actingAs($this->user($role));
            foreach (array_keys(OperationalCatalog::DEFINITIONS) as $catalog) {
                $url = '/catalogos/'.$catalog;
                $this->get($url)->assertForbidden();
                $this->get($url.'/nuevo')->assertForbidden();
                $this->post($url, [])->assertForbidden();
                $this->patch($url.'/1', [])->assertForbidden();
                $this->patch($url.'/1/estado', ['status' => 2])->assertForbidden();
            }
        }
        foreach ([1, 2] as $role) {
            $this->actingAs($this->user($role))->withSession(['sucursal_activa' => 1]);
            foreach (array_keys(OperationalCatalog::DEFINITIONS) as $catalog) {
                if ($catalog === 'iso') {
                    $this->get('/catalogos/'.$catalog)->assertRedirect('/ingresos');
                    $this->get('/catalogos/'.$catalog.'/nuevo')->assertRedirect('/ingresos');
                } else {
                    $this->get('/catalogos/'.$catalog)->assertOk();
                    $this->get('/catalogos/'.$catalog.'/nuevo')->assertOk();
                }
            }
        }
        $this->get('/catalogos/users')->assertNotFound();
        $this->get('/catalogos/navieras/999/editar')->assertNotFound();
    }

    public function test_shipping_line_interface_saves_json_and_filters_active_and_inactive_records(): void
    {
        $this->actingAs($this->user());
        $data = ['name' => 'Naviera interfaz', 'branches' => [1], 'email' => 'naviera@example.com'];
        $this->postJson('/catalogos/navieras', $data)->assertOk()->assertJsonPath('message', 'Naviera creada correctamente.');
        $id = DB::table('shipping_lines')->where('name', $data['name'])->value('id');
        $this->postJson('/catalogos/navieras', $data)->assertUnprocessable()->assertJsonValidationErrors('name_key');
        $this->patchJson('/catalogos/navieras/'.$id, array_replace($data, ['branches' => [1, 2]]))
            ->assertOk()->assertJsonPath('message', 'Naviera actualizada correctamente.');
        $this->patchJson('/catalogos/navieras/'.$id, array_replace($data, ['branches' => [], 'email' => 'incorrecto']))
            ->assertUnprocessable()->assertJsonValidationErrors(['branches', 'email']);
        $this->assertDatabaseCount('shipping_line_branch', 2);
        $this->patchJson('/catalogos/navieras/'.$id.'/estado', ['status' => 2])->assertOk();
        $this->get('/catalogos/navieras')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 0);
        foreach (['estado=inactivos', 'estado=todos', 'status=inactive'] as $query) {
            $this->get('/catalogos/navieras?'.$query)->assertOk()
                ->assertViewHas('records', fn ($records) => $records->total() === 1)
                ->assertSee('Opciones')->assertSee('shippingLineStatusDialog', false)
                ->assertSee('Reactivar')
                ->assertDontSee(route('catalogs.edit', ['catalog' => 'navieras', 'record' => $id]), false)
                ->assertDontSee('catalogStatusDialog', false);
        }
        $this->patchJson('/catalogos/navieras/'.$id.'/estado', ['status' => 1])->assertOk()->assertJsonPath('message', 'Naviera reactivada.');
        $this->assertDatabaseCount('shipping_line_branch', 2);
        $this->get('/catalogos/navieras')->assertOk()->assertSee(route('catalogs.edit', ['catalog' => 'navieras', 'record' => $id]), false)->assertSee('Desactivar');
        $this->get('/catalogos/navieras/'.$id.'/editar')->assertOk()->assertSee('data-shipping-line-form', false);
        $this->get('/catalogos/navieras/nuevo')->assertOk()->assertSee('role="tablist"', false)
            ->assertSee('data-shipping-line-panel="general"', false)->assertSee('data-shipping-line-panel="operation" hidden', false);
        $this->get('/catalogos/auditoras/nuevo')->assertOk()->assertDontSee('data-shipping-line-tab', false);
    }

    public function test_parts_interface_validates_forms_and_reactivates_inactive_records(): void
    {
        $this->actingAs($this->user());
        $data = ['code' => ' rep-01 ', 'name' => 'Bisagra', 'unit' => 'Pieza', 'currency' => 'GTQ', 'reference_cost' => '12.50', 'description' => str_repeat('a', 255)];
        $this->postJson('/catalogos/repuestos', $data)->assertOk()->assertJsonPath('message', 'Repuesto creado correctamente.');
        $id = DB::table('repair_parts')->where('name', 'Bisagra')->value('id');
        $code = 'REP-BISAGRA-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $this->assertDatabaseHas('repair_parts', ['id' => $id, 'code' => $code]);
        $this->patchJson('/catalogos/repuestos/'.$id, array_replace($data, ['description' => str_repeat('a', 256), 'reference_cost' => '-1', 'currency' => 'EUR', 'unit' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['description', 'reference_cost', 'currency', 'unit']);
        $this->patchJson('/catalogos/repuestos/'.$id, array_replace($data, ['reference_cost' => '1.234']))->assertUnprocessable()->assertJsonValidationErrors('reference_cost');
        $this->get('/catalogos/repuestos')->assertOk()->assertSee('Nuevo repuesto')->assertSee('shippingLineStatusDialog', false)->assertDontSee('catalogStatusDialog', false);
        $this->get('/catalogos/repuestos/'.$id.'/editar')->assertOk()->assertSee('data-catalog-item-form', false)->assertSee('maxlength="255"', false);
        $this->patchJson('/catalogos/repuestos/'.$id, array_replace($data, ['reference_cost' => null]))->assertOk()->assertJsonPath('message', 'Repuesto actualizado correctamente.');
        $this->patchJson('/catalogos/repuestos/'.$id.'/estado', ['status' => 2])->assertOk();
        $this->get('/catalogos/repuestos')->assertViewHas('records', fn ($records) => $records->total() === 0);
        foreach (['estado=inactivos', 'estado=todos', 'status=inactive'] as $query) {
            $this->get('/catalogos/repuestos?'.$query)->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1)
                ->assertSee('Reactivar')->assertDontSee(route('catalogs.edit', ['catalog' => 'repuestos', 'record' => $id]), false);
        }
        $this->get('/catalogos/repuestos/'.$id.'/editar')->assertForbidden();
        $this->patchJson('/catalogos/repuestos/'.$id, $data)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson('/catalogos/repuestos/'.$id.'/estado', ['status' => 1])->assertOk()->assertJsonPath('message', 'Repuesto reactivado.');
        $this->assertDatabaseHas('repair_parts', ['id' => $id, 'status' => 1, 'description' => $data['description']]);
    }

    public function test_parts_generate_stable_codes_and_only_accept_catalog_units(): void
    {
        $this->actingAs($this->user());
        $data = ['name' => 'Bisagra de puerta', 'unit' => 'Pieza', 'currency' => 'GTQ'];
        $this->get('/catalogos/repuestos/nuevo')->assertOk()->assertDontSee('name="code"', false)
            ->assertSee('readonly', false)->assertSee('<select name="unit"', false);
        foreach (OperationalCatalog::PART_UNITS as $unit) {
            $this->postJson('/catalogos/repuestos', array_replace($data, ['unit' => $unit, 'code' => 'MANUAL']))->assertOk();
        }
        $codes = DB::table('repair_parts')->pluck('code');
        $this->assertCount(count(OperationalCatalog::PART_UNITS), $codes->unique());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^REP-BISAGRA-DE-PUERTA-\d{6}$/', $code);
            $this->assertLessThanOrEqual(30, strlen($code));
        }
        $part = DB::table('repair_parts')->first();
        $this->patchJson('/catalogos/repuestos/'.$part->id, array_replace($data, ['name' => 'Otro nombre', 'code' => 'CAMBIADO']))->assertOk();
        $this->assertDatabaseHas('repair_parts', ['id' => $part->id, 'code' => $part->code]);
        $this->postJson('/catalogos/repuestos', array_replace($data, ['unit' => 'Inventada']))->assertUnprocessable()->assertJsonValidationErrors('unit');
        $this->postJson('/catalogos/repuestos', array_replace($data, ['reference_cost' => '-0.01']))->assertUnprocessable()->assertJsonValidationErrors('reference_cost');
        $this->postJson('/catalogos/repuestos', array_replace($data, ['reference_cost' => '0']))->assertOk();
        $this->postJson('/catalogos/repuestos', array_replace($data, ['name' => str_repeat('a', 120)]))->assertOk();
        $this->assertLessThanOrEqual(30, strlen(DB::table('repair_parts')->orderByDesc('id')->value('code')));
        DB::table('repair_parts')->where('id', $part->id)->update(['code' => 'LEGADO-1', 'unit' => 'Unidad antigua']);
        $this->get('/catalogos/repuestos/'.$part->id.'/editar')->assertOk()->assertSee('Unidad antigua')->assertSee('LEGADO-1');
        $this->patchJson('/catalogos/repuestos/'.$part->id, array_replace($data, ['unit' => 'Unidad antigua']))->assertOk();
        $this->assertDatabaseHas('repair_parts', ['id' => $part->id, 'code' => 'LEGADO-1', 'unit' => 'Unidad antigua']);
        $this->postJson('/catalogos/repuestos', array_replace($data, ['unit' => 'Unidad antigua']))->assertUnprocessable()->assertJsonValidationErrors('unit');
        $event = DB::table('operational_catalog_events')->where('record_id', $part->id)->where('action', 'Creacion')->first();
        $this->assertSame($part->code, json_decode($event->after_data, true)['code']);
    }

    public function test_repair_interface_validates_forms_and_preserves_inactive_records(): void
    {
        $this->actingAs($this->user());
        $data = ['code' => ' repa-01 ', 'name' => 'Soldadura', 'currency' => 'GTQ', 'reference_cost' => '50.00', 'description' => str_repeat('a', 255)];
        $this->postJson('/catalogos/reparaciones', $data)->assertOk()->assertJsonPath('message', 'Reparacion creada correctamente.');
        $id = DB::table('repair_services')->where('name', 'Soldadura')->value('id');
        $this->assertDatabaseHas('repair_services', ['id' => $id, 'code' => 'RPR-SOLDADURA-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
        $this->patchJson('/catalogos/reparaciones/'.$id, array_replace($data, ['description' => str_repeat('a', 256), 'reference_cost' => '-1', 'currency' => 'EUR', 'unit' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['description', 'reference_cost', 'currency']);
        $this->patchJson('/catalogos/reparaciones/'.$id, array_replace($data, ['reference_cost' => '1.234']))->assertUnprocessable()->assertJsonValidationErrors('reference_cost');
        $this->get('/catalogos/reparaciones')->assertOk()->assertSee('Nueva reparacion')->assertSee('Tarifa de referencia')->assertSee('shippingLineStatusDialog', false)->assertDontSee('catalogStatusDialog', false);
        $this->get('/catalogos/reparaciones/nuevo')->assertOk()->assertSee('data-catalog-item-form', false)->assertDontSee('Unidad de cobro')->assertDontSee('name="unit"', false)->assertSee('maxlength="255"', false);
        $this->patchJson('/catalogos/reparaciones/'.$id, array_replace($data, ['reference_cost' => null]))->assertOk()->assertJsonPath('message', 'Reparacion actualizada correctamente.');
        $this->patchJson('/catalogos/reparaciones/'.$id.'/estado', ['status' => 2])->assertOk()->assertJsonPath('message', 'Reparacion desactivada. Su historial se conserva.');
        $this->get('/catalogos/reparaciones')->assertViewHas('records', fn ($records) => $records->total() === 0);
        foreach (['estado=inactivos', 'estado=todos', 'status=inactive'] as $query) {
            $this->get('/catalogos/reparaciones?'.$query)->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1)
                ->assertSee('Reactivar')->assertDontSee(route('catalogs.edit', ['catalog' => 'reparaciones', 'record' => $id]), false);
        }
        $this->get('/catalogos/reparaciones/'.$id.'/editar')->assertForbidden();
        $this->patchJson('/catalogos/reparaciones/'.$id, $data)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson('/catalogos/reparaciones/'.$id.'/estado', ['status' => 1])->assertOk()->assertJsonPath('message', 'Reparacion reactivada.');
        $this->get('/catalogos/reparaciones/'.$id.'/editar')->assertOk()->assertSee('Editar reparacion');
        $this->assertDatabaseHas('repair_services', ['id' => $id, 'status' => 1, 'reference_cost' => null, 'description' => $data['description']]);
        $this->assertDatabaseCount('operational_catalog_events', 4);
    }

    public function test_repairs_generate_stable_codes_and_always_use_one_service(): void
    {
        $this->actingAs($this->user());
        $data = ['name' => 'Soldadura', 'unit' => 'Servicio', 'currency' => 'GTQ'];
        $this->get('/catalogos/reparaciones/nuevo')->assertOk()->assertDontSee('name="code"', false)
            ->assertSee('readonly', false)->assertDontSee('<select name="unit"', false)->assertSee('data-nonnegative-cost', false);
        foreach (['Servicio', 'Hora', 'Unidad', 'Inventada'] as $unit) {
            $this->postJson('/catalogos/reparaciones', array_replace($data, ['unit' => $unit, 'code' => 'MANUAL']))->assertOk();
        }
        $codes = DB::table('repair_services')->pluck('code');
        $this->assertCount(4, $codes->unique());
        $this->assertSame(['Servicio'], DB::table('repair_services')->distinct()->pluck('unit')->all());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^RPR-SOLDADURA-\d{6}$/', $code);
        }
        $record = DB::table('repair_services')->first();
        $this->patchJson('/catalogos/reparaciones/'.$record->id, array_replace($data, ['name' => 'Pintura', 'code' => 'CAMBIADO']))->assertOk();
        $this->assertDatabaseHas('repair_services', ['id' => $record->id, 'code' => $record->code]);
        $this->postJson('/catalogos/reparaciones', ['name' => 'Sin selector', 'currency' => 'GTQ'])->assertOk();
        $this->assertDatabaseHas('repair_services', ['name' => 'Sin selector', 'unit' => 'Servicio']);
        $this->postJson('/catalogos/reparaciones', array_replace($data, ['reference_cost' => '-0.01']))->assertUnprocessable()->assertJsonValidationErrors('reference_cost');
        $this->postJson('/catalogos/reparaciones', array_replace($data, ['reference_cost' => '0']))->assertOk();
        $this->postJson('/catalogos/reparaciones', array_replace($data, ['name' => str_repeat('a', 120)]))->assertOk();
        $this->assertLessThanOrEqual(30, strlen(DB::table('repair_services')->orderByDesc('id')->value('code')));
        DB::table('repair_services')->where('id', $record->id)->update(['code' => 'ANTIGUO-1', 'unit' => 'Trabajo antiguo']);
        $this->get('/catalogos/reparaciones/'.$record->id.'/editar')->assertOk()->assertDontSee('Trabajo antiguo')->assertSee('ANTIGUO-1');
        $this->patchJson('/catalogos/reparaciones/'.$record->id, array_replace($data, ['unit' => 'Trabajo antiguo']))->assertOk();
        $this->assertDatabaseHas('repair_services', ['id' => $record->id, 'code' => 'ANTIGUO-1', 'unit' => 'Servicio']);
        $event = DB::table('operational_catalog_events')->where('catalog', 'reparaciones')->where('record_id', $record->id)->where('action', 'Creacion')->first();
        $this->assertSame($record->code, json_decode($event->after_data, true)['code']);
    }

    public function test_shipping_line_description_and_regional_phone_validation(): void
    {
        $this->actingAs($this->user());
        $valid = ['5555-1234' => '55551234', '+502 (2345) 6789' => '+50223456789', '+503 7777-1234' => '+50377771234', '00503 2222-1234' => '+50322221234', '+50383746573' => '+50383746573', '12345678' => '12345678', '+50285551234' => '+50285551234', ' ' => null];
        foreach ($valid as $phone => $normalized) {
            $name = 'Naviera '.count(DB::table('shipping_lines')->get());
            $this->postJson('/catalogos/navieras', ['name' => $name, 'branches' => [1], 'phone' => (string) $phone, 'description' => str_repeat('a', 255)])->assertOk();
            $this->assertDatabaseHas('shipping_lines', ['name' => $name, 'phone' => $normalized]);
        }
        foreach (['5555123', '555512345', '+50455551234', 'abc55551234', '+50255551234 ext 1'] as $phone) {
            $this->postJson('/catalogos/navieras', ['name' => 'No guardar', 'branches' => [1], 'phone' => $phone])->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $id = DB::table('shipping_lines')->first()->id;
        $this->patchJson('/catalogos/navieras/'.$id, ['name' => 'No guardar', 'branches' => [1], 'description' => str_repeat('a', 256)])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertDatabaseMissing('shipping_lines', ['name' => 'No guardar']);
    }

    public function test_shipping_line_phone_country_selector_saves_and_clears_phone(): void
    {
        $this->actingAs($this->user());
        $data = ['name' => 'Naviera prefijo', 'branches' => [1], 'phone_country' => '503', 'phone_national' => '77771234'];
        $this->postJson('/catalogos/navieras', $data)->assertOk();
        $id = DB::table('shipping_lines')->where('name', $data['name'])->value('id');
        $this->assertDatabaseHas('shipping_lines', ['id' => $id, 'phone' => '+50377771234']);
        $this->get('/catalogos/navieras/'.$id.'/editar')->assertOk()->assertSee('value="77771234"', false)->assertSee('value="503" selected', false);
        $this->patchJson('/catalogos/navieras/'.$id, array_replace($data, ['phone_country' => '502', 'phone_national' => '55551234']))->assertOk();
        $this->assertDatabaseHas('shipping_lines', ['id' => $id, 'phone' => '+50255551234']);
        $this->patchJson('/catalogos/navieras/'.$id, array_replace($data, ['phone_country' => '504']))->assertUnprocessable()->assertJsonValidationErrors('phone_country');
        $this->patchJson('/catalogos/navieras/'.$id, array_replace($data, ['phone_national' => '83746573']))->assertOk();
        $this->assertDatabaseHas('shipping_lines', ['id' => $id, 'phone' => '+50383746573']);
        $this->patchJson('/catalogos/navieras/'.$id, array_replace($data, ['phone_national' => '123']))->assertUnprocessable()->assertJsonValidationErrors('phone_national');
        $this->patchJson('/catalogos/navieras/'.$id, array_replace($data, ['phone_national' => '']))->assertOk();
        $this->assertDatabaseHas('shipping_lines', ['id' => $id, 'phone' => null]);
    }

    public function test_catalog_pagination_supports_the_inventory_sizes_and_preserves_filters(): void
    {
        $this->actingAs($this->user());
        for ($i = 1; $i <= 28; $i++) {
            DB::table('external_audit_companies')->insert(['name' => sprintf('Auditora %02d', $i), 'name_key' => 'auditora '.$i, 'status' => 1]);
        }
        foreach ([5, 10, 25] as $size) {
            $response = $this->get('/catalogos/auditoras?per_page='.$size.'&q=Auditora&page=2');
            $response->assertOk()->assertViewHas('records', fn ($records) => $records->perPage() === $size && $records->currentPage() === 2 && $records->total() === 28);
            $response->assertSee('per_page='.$size, false)->assertSee('q=Auditora', false);
        }
        $this->get('/catalogos/auditoras?per_page=999')->assertOk()->assertViewHas('records', fn ($records) => $records->perPage() === 10);
        $this->get('/catalogos/auditoras?per_page=invalid')->assertOk()->assertViewHas('records', fn ($records) => $records->perPage() === 10);
    }

    public function test_auditor_interface_validates_json_forms_and_preserves_links_when_status_changes(): void
    {
        $this->actingAs($this->user());
        $data = ['name' => 'Auditora interfaz', 'contact_name' => 'Ana', 'email' => 'ana@example.com', 'phone_country' => '503', 'phone_national' => '83746573', 'description' => str_repeat('a', 255)];
        $this->postJson('/catalogos/auditoras', $data)->assertOk()->assertJsonPath('message', 'Auditora creada correctamente.');
        $id = DB::table('external_audit_companies')->where('name', $data['name'])->value('id');
        $this->assertDatabaseHas('external_audit_companies', ['id' => $id, 'phone' => '+50383746573']);
        $this->postJson('/catalogos/auditoras', array_replace($data, ['name' => '  AUDITORA   INTERFAZ  ']))->assertUnprocessable()->assertJsonValidationErrors('name_key');
        $this->patchJson('/catalogos/auditoras/'.$id, array_replace($data, ['email' => 'incorrecto', 'phone_national' => '123', 'description' => str_repeat('a', 256)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone_national', 'description']);
        $this->patchJson('/catalogos/auditoras/'.$id, array_replace($data, ['phone_national' => '']))->assertOk()->assertJsonPath('message', 'Auditora actualizada correctamente.');
        $this->assertDatabaseHas('external_audit_companies', ['id' => $id, 'phone' => null]);
        $shippingId = DB::table('shipping_lines')->insertGetId(['name' => 'Naviera vinculada', 'name_key' => 'naviera vinculada', 'status' => 1]);
        DB::table('shipping_line_auditor')->insert(['shipping_line_id' => $shippingId, 'auditor_id' => $id]);
        $this->get('/catalogos/auditoras?filters[contact]=Ana')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1)
            ->assertSee('Nueva auditora')->assertSee('data-shipping-line-status', false)->assertDontSee('catalogStatusDialog', false);
        $this->patchJson('/catalogos/auditoras/'.$id.'/estado', ['status' => 2])->assertOk()->assertJsonPath('message', 'Auditora desactivada. Su historial se conserva.');
        $this->get('/catalogos/auditoras')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 0);
        foreach (['estado=inactivos', 'estado=todos', 'status=inactive'] as $query) {
            $this->get('/catalogos/auditoras?'.$query)->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1)
                ->assertSee('Reactivar')->assertDontSee(route('catalogs.edit', ['catalog' => 'auditoras', 'record' => $id]), false);
        }
        $this->patchJson('/catalogos/auditoras/'.$id.'/estado', ['status' => 1])->assertOk()->assertJsonPath('message', 'Auditora reactivada.');
        $this->assertDatabaseHas('shipping_line_auditor', ['shipping_line_id' => $shippingId, 'auditor_id' => $id]);
        $this->get('/catalogos/auditoras/'.$id.'/editar')->assertOk()->assertSee('Editar auditora')->assertSee('data-shipping-line-email', false)->assertDontSee('data-relation-trigger', false);
    }

    public function test_table_search_column_filters_and_sorting_apply_before_pagination(): void
    {
        $this->actingAs($this->user());
        DB::table('external_audit_companies')->insert([
            ['name' => 'Auditora Z', 'name_key' => 'auditora z', 'contact_name' => 'Ana', 'email' => 'ana@example.com', 'status' => 1],
            ['name' => 'Auditora A', 'name_key' => 'auditora a', 'contact_name' => 'Beto', 'email' => 'beto@example.com', 'status' => 1],
            ['name' => 'Auditora inactiva', 'name_key' => 'auditora inactiva', 'contact_name' => 'Ana', 'email' => 'other@example.com', 'status' => 2],
        ]);
        $this->get('/catalogos/auditoras?q=Ana')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->name === 'Auditora Z');
        $this->get('/catalogos/auditoras?filters[contact]=Beto')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->name === 'Auditora A');
        $this->get('/catalogos/auditoras?filters[email]=ana@example.com')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->name === 'Auditora Z');
        DB::table('external_audit_companies')->where('name', 'Auditora A')->update(['phone' => '+50383746573']);
        $this->get('/catalogos/auditoras?filters[phone]=83746573')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->name === 'Auditora A');
        $this->get('/catalogos/auditoras?filters[email]=ana@example.com&filters[phone]=83746573')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 0);
        $this->get('/catalogos/auditoras?sort=email&order=desc')->assertOk()->assertViewHas('records', fn ($records) => $records->first()->name === 'Auditora A');
        $this->get('/catalogos/auditoras?sort=phone&order=desc')->assertOk()->assertViewHas('records', fn ($records) => $records->first()->name === 'Auditora Z');
        $this->get('/catalogos/auditoras')->assertOk()->assertDontSee('sort=phone', false)->assertSee('filters[phone]', false);
        $this->get('/catalogos/auditoras?sort=name&order=desc')->assertOk()->assertViewHas('records', fn ($records) => $records->first()->name === 'Auditora Z');
        $this->get('/catalogos/auditoras?sort=unknown&order=invalid')->assertOk()->assertViewHas('records', fn ($records) => $records->first()->name === 'Auditora A');
        $this->get('/catalogos/auditoras?page=999')->assertOk()->assertViewHas('records', fn ($records) => $records->currentPage() === 1 && $records->count() === 2);
        $this->get('/catalogos/iso?filters[dimensions]=2.896')->assertRedirect('/ingresos');
        $this->actingAs($this->user(1));
        $this->get('/sucursales?filters[name]=Sucursal B')->assertOk()->assertViewHas('branches', fn ($records) => $records->total() === 1 && $records->first()->id === 2);
        $this->get('/sucursales?q=inactiva')->assertOk()->assertViewHas('branches', fn ($records) => $records->total() === 0);
        $this->get('/sucursales?estado=inactivas')->assertOk()->assertViewHas('branches', fn ($records) => $records->total() === 1 && $records->first()->id === 3);
        $this->get('/sucursales?estado=todas')->assertOk()->assertViewHas('branches', fn ($records) => $records->total() === 3);
        $this->get('/sucursales?estado=invalid')->assertOk()->assertViewHas('branches', fn ($records) => $records->total() === 2);
    }

    public function test_branch_modals_save_json_and_retain_server_validation(): void
    {
        $this->actingAs($this->user(1));
        $this->postJson('/sucursales', ['nombre' => 'Sucursal Modal', 'departamento_codigo' => '18', 'municipio_codigo' => '1801'])
            ->assertOk()->assertJsonPath('message', 'Sucursal creada correctamente.');
        $id = DB::table('sucursales')->where('nombre', 'Sucursal Modal')->value('id');
        $this->patchJson('/sucursales/'.$id, ['nombre' => 'Sucursal Modal', 'departamento_codigo' => '18', 'municipio_codigo' => '1804', 'direccion' => 'Direccion actualizada'])
            ->assertOk()->assertJsonPath('message', 'Sucursal actualizada correctamente.');
        $this->assertDatabaseHas('sucursales', ['id' => $id, 'ubicacion' => 'Morales, Izabal', 'departamento_codigo' => '18', 'municipio_codigo' => '1804', 'direccion' => 'Direccion actualizada']);
        $this->postJson('/sucursales', ['nombre' => 'Sucursal Modal', 'departamento_codigo' => '18', 'municipio_codigo' => '1801'])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->patchJson('/sucursales/'.$id, ['nombre' => '', 'ubicacion' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre', 'departamento_codigo', 'municipio_codigo']);
        $this->actingAs($this->user(2))->postJson('/sucursales', ['nombre' => 'Sin permiso', 'ubicacion' => 'Otra'])
            ->assertForbidden();
    }

    public function test_geography_is_predefined_and_rejects_municipalities_from_other_departments(): void
    {
        $departments = GuatemalaGeography::departments();
        $this->assertCount(22, $departments);
        $municipalities = collect($departments)->flatMap(fn ($department) => $department['municipalities']);
        $this->assertCount(340, $municipalities);
        $this->assertCount(340, $municipalities->unique('code'));
        foreach ($departments as $department) {
            foreach ($department['municipalities'] as $municipality) {
                $this->assertSame($department['code'], substr($municipality['code'], 0, 2));
                $this->assertNotEmpty($municipality['name']);
            }
        }
        $this->actingAs($this->user(1));
        $this->postJson('/sucursales', ['nombre' => 'Ubicacion invalida', 'departamento_codigo' => '18', 'municipio_codigo' => '0101'])
            ->assertUnprocessable()->assertJsonValidationErrors('municipio_codigo');
        $this->postJson('/sucursales', ['nombre' => 'Departamento invalido', 'departamento_codigo' => '99', 'municipio_codigo' => '9901'])
            ->assertUnprocessable()->assertJsonValidationErrors(['departamento_codigo', 'municipio_codigo']);
        $this->postJson('/sucursales', ['nombre' => 'Sin direccion', 'departamento_codigo' => '18', 'municipio_codigo' => '1801'])
            ->assertOk();
        $this->assertDatabaseHas('sucursales', ['nombre' => 'Sin direccion', 'ubicacion' => 'Puerto Barrios, Izabal', 'direccion' => null]);
        $this->assertDatabaseHas('sucursales', ['id' => 1, 'departamento_codigo' => null, 'municipio_codigo' => null]);
    }

    public function test_branch_reactivation_preserves_data_and_does_not_reactivate_users_or_yards(): void
    {
        Schema::create('patios', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('sucursal_id');
            $table->integer('estado_id');
        });
        DB::table('users')->insert(['id' => 10, 'sucursal_id' => 3, 'estado_id' => 2]);
        DB::table('patios')->insert(['id' => 10, 'sucursal_id' => 3, 'estado_id' => 2]);
        DB::table('sucursales')->where('id', 3)->update(['ubicacion' => 'Puerto Barrios, Izabal', 'departamento_codigo' => '18', 'municipio_codigo' => '1801', 'direccion' => 'Direccion conservada']);
        $this->actingAs($this->user(2))->patch('/sucursales/3/reactivar')->assertForbidden();
        $this->actingAs($this->user(1))->from('/sucursales?estado=inactivas')->patch('/sucursales/3/reactivar')
            ->assertRedirect('/sucursales?estado=inactivas')->assertSessionHas('status');
        $this->assertDatabaseHas('sucursales', ['id' => 3, 'estado_id' => 1, 'departamento_codigo' => '18', 'municipio_codigo' => '1801', 'direccion' => 'Direccion conservada']);
        $this->assertDatabaseHas('users', ['id' => 10, 'estado_id' => 2]);
        $this->assertDatabaseHas('patios', ['id' => 10, 'estado_id' => 2]);
        $this->patch('/sucursales/3/reactivar')->assertNotFound();
        $this->patch('/sucursales/999/reactivar')->assertNotFound();
        DB::table('sucursales')->insert(['id' => 4, 'nombre' => 'Sucursal A', 'estado_id' => 2]);
        $this->patch('/sucursales/4/reactivar')->assertRedirect()->assertSessionHasErrors('branch');
        $this->assertDatabaseHas('sucursales', ['id' => 4, 'estado_id' => 2]);
        DB::table('users')->insert(['id' => 11, 'sucursal_id' => 1, 'estado_id' => 1]);
        $this->delete('/sucursales/1')->assertRedirect()->assertSessionHasErrors('branch');
        $this->assertDatabaseHas('sucursales', ['id' => 1, 'estado_id' => 1]);
        $this->assertDatabaseHas('users', ['id' => 11, 'estado_id' => 1]);
        $this->deleteJson('/sucursales/1')->assertUnprocessable()->assertJsonValidationErrors('branch');
        DB::table('patios')->insert(['id' => 11, 'sucursal_id' => 2, 'estado_id' => 1]);
        $this->deleteJson('/sucursales/2')->assertUnprocessable()->assertJsonValidationErrors('branch');
        $this->assertDatabaseHas('sucursales', ['id' => 2, 'estado_id' => 1]);
        DB::table('patios')->where('id', 11)->update(['estado_id' => 2]);
        $this->withSession(['sucursal_activa' => 1])->deleteJson('/sucursales/2')->assertOk()->assertJsonPath('refreshShell', false);
        $this->assertDatabaseHas('sucursales', ['id' => 2, 'estado_id' => 2]);
    }

    public function test_related_columns_can_be_searched_filtered_and_sorted(): void
    {
        $this->actingAs($this->user());
        $this->post('/catalogos/auditoras', ['name' => 'Inspectores del puerto']);
        $auditor = DB::table('external_audit_companies')->value('id');
        $this->post('/catalogos/navieras', ['name' => 'Naviera B', 'branches' => [2], 'auditor_assignments' => [2 => [$auditor]]]);
        $this->post('/catalogos/navieras', ['name' => 'Naviera A', 'branches' => [1], 'auditors' => []]);
        $this->get('/catalogos/navieras?filters[branches]=Sucursal B')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->name === 'Naviera B');
        $this->get('/catalogos/navieras?q=Inspectores')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->name === 'Naviera B');
        $this->get('/catalogos/navieras?sort=branches&order=desc')->assertOk()->assertViewHas('records', fn ($records) => $records->first()->name === 'Naviera B');
    }

    public function test_shipping_lines_support_multiple_branches_and_shared_auditors(): void
    {
        $this->actingAs($this->user());
        $this->post('/catalogos/auditoras', ['name' => 'Auditora A'])->assertSessionHasNoErrors();
        $auditor = (int) DB::table('external_audit_companies')->value('id');
        foreach (['Naviera A', 'Naviera B'] as $name) {
            $this->post('/catalogos/navieras', ['name' => $name, 'branches' => [1, 2], 'auditor_assignments' => [1 => [$auditor], 2 => [$auditor]]])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('shipping_line_branch', 4);
        $this->assertDatabaseCount('shipping_line_branch_auditor', 4);
        $id = (int) DB::table('shipping_lines')->where('name', 'Naviera A')->value('id');
        $this->get('/catalogos/navieras')->assertOk()->assertSee('Auditora A')->assertSee('Sucursal B');
        $this->get("/catalogos/navieras/{$id}/editar")->assertOk();
        $this->patch("/catalogos/navieras/{$id}", ['name' => 'Naviera A editada', 'branches' => [2]])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('shipping_line_branch', ['shipping_line_id' => $id, 'branch_id' => 1]);
        $this->assertDatabaseMissing('shipping_line_branch_auditor', ['shipping_line_id' => $id]);
        $this->assertDatabaseCount('operational_catalog_events', 4);
        $event = DB::table('operational_catalog_events')->orderByDesc('id')->first();
        $this->assertSame([1, 2], json_decode($event->before_data, true)['branches']);
        $this->assertSame([2], json_decode($event->after_data, true)['branches']);
    }

    public function test_deactivation_preserves_links_blocks_new_inactive_links_and_allows_reactivation(): void
    {
        $this->actingAs($this->user());
        $this->post('/catalogos/auditoras', ['name' => 'Auditora A']);
        $auditor = (int) DB::table('external_audit_companies')->value('id');
        $this->post('/catalogos/navieras', ['name' => 'Naviera A', 'branches' => [1], 'auditor_assignments' => [1 => [$auditor]]]);
        $id = (int) DB::table('shipping_lines')->value('id');
        $this->patch("/catalogos/auditoras/{$auditor}/estado", ['status' => 2])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('shipping_line_branch_auditor', ['shipping_line_id' => $id, 'branch_id' => 1, 'auditor_id' => $auditor]);
        $this->post('/catalogos/navieras', ['name' => 'Naviera B', 'branches' => [1], 'auditor_assignments' => [1 => [$auditor]]])->assertSessionHasErrors('auditor_assignments.1');
        $this->patch("/catalogos/navieras/{$id}", ['name' => 'Naviera A', 'branches' => [1], 'auditor_assignments' => [1 => [$auditor]]])->assertSessionHasNoErrors();
        $this->post('/catalogos/navieras', ['name' => 'Naviera C', 'branches' => [3]])->assertSessionHasErrors('branches');
        $this->patch("/catalogos/navieras/{$id}/estado", ['status' => 2])->assertSessionHasNoErrors();
        $this->get('/catalogos/navieras')->assertDontSee('Naviera A');
        $this->get('/catalogos/navieras?status=inactive')->assertSee('Naviera A');
        $this->post('/catalogos/navieras', ['name' => '  NAVIERA   A ', 'branches' => [1]])->assertSessionHasErrors('name_key');
        $this->patch("/catalogos/navieras/{$id}/estado", ['status' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('shipping_lines', ['id' => $id, 'status' => 1]);
        $this->assertDatabaseHas('shipping_line_branch_auditor', ['shipping_line_id' => $id, 'branch_id' => 1, 'auditor_id' => $auditor]);
    }

    public function test_auditor_authorizations_are_isolated_by_shipping_line_and_branch(): void
    {
        $this->actingAs($this->user());
        $auditorA = DB::table('external_audit_companies')->insertGetId(['name' => 'Auditora A', 'name_key' => 'auditora a', 'status' => 1]);
        $auditorB = DB::table('external_audit_companies')->insertGetId(['name' => 'Auditora B', 'name_key' => 'auditora b', 'status' => 1]);
        $data = ['name' => 'Naviera por sede', 'branches' => [1, 2], 'auditor_assignments' => [1 => [$auditorA], 2 => [$auditorB]]];
        $this->postJson('/catalogos/navieras', $data)->assertOk();
        $id = DB::table('shipping_lines')->value('id');
        $catalog = app(OperationalCatalog::class);
        $this->assertSame([$auditorA], $catalog->authorizedAuditors($id, 1)->pluck('id')->all());
        $this->assertSame([$auditorB], $catalog->authorizedAuditors($id, 2)->pluck('id')->all());
        $this->assertTrue($catalog->authorizedAuditors($id, 3)->isEmpty());
        $this->assertSame([1 => [$auditorA], 2 => [$auditorB]], $catalog->snapshot('navieras', $id)['auditor_assignments']);
        $this->get('/catalogos/navieras?filters[auditors]=Auditora B')->assertViewHas('records', fn ($items) => $items->total() === 1);
        $this->get('/catalogos/navieras/'.$id.'/editar')->assertOk()->assertSee('auditor_assignments[1][]', false)->assertSee('auditor_assignments[2][]', false);
        foreach ([['branches' => [1]], ['auditor_assignments' => [1 => [$auditorA, $auditorA]]], ['auditor_assignments' => [99 => [$auditorA]]]] as $invalid) {
            $this->patchJson('/catalogos/navieras/'.$id, array_replace($data, $invalid))->assertUnprocessable();
            $this->assertDatabaseCount('shipping_line_branch_auditor', 2);
        }
        $this->patchJson('/catalogos/navieras/'.$id, array_replace($data, ['auditor_assignments' => [1 => [$auditorA], 2 => [$auditorA, $auditorB]]]))->assertOk();
        $this->assertSame([$auditorA, $auditorB], $catalog->authorizedAuditors($id, 2)->pluck('id')->all());
        DB::table('external_audit_companies')->where('id', $auditorB)->update(['status' => 2]);
        $this->assertSame([$auditorA], $catalog->authorizedAuditors($id, 2)->pluck('id')->all());
        $this->patchJson('/catalogos/navieras/'.$id, $data)->assertOk();
        $this->patchJson('/catalogos/navieras/'.$id, array_replace($data, ['auditor_assignments' => [1 => [$auditorA, $auditorB], 2 => [$auditorB]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('auditor_assignments.1');
        DB::table('sucursales')->where('id', 1)->update(['estado_id' => 2]);
        $this->assertTrue($catalog->authorizedAuditors($id, 1)->isEmpty());
        $this->patchJson('/catalogos/navieras/'.$id, $data)->assertOk();
        $this->patchJson('/catalogos/navieras/'.$id.'/estado', ['status' => 2])->assertOk();
        $this->assertTrue($catalog->authorizedAuditors($id, 2)->isEmpty());
        $this->assertDatabaseCount('shipping_line_branch_auditor', 2);
        $event = DB::table('operational_catalog_events')->where('catalog', 'navieras')->orderByDesc('id')->first();
        $this->assertSame([1 => [$auditorA], 2 => [$auditorB]], json_decode($event->after_data, true)['auditor_assignments']);
    }

    public function test_migration_preserves_unscoped_links_without_inventing_authorizations(): void
    {
        $this->actingAs($this->user());
        $migration = require database_path('migrations/2026_10_13_202712_create_shipping_line_branch_auditor_table.php');
        $migration->down();
        $id = DB::table('shipping_lines')->insertGetId(['name' => 'Naviera anterior', 'name_key' => 'naviera anterior', 'status' => 1]);
        $auditor = DB::table('external_audit_companies')->insertGetId(['name' => 'Auditora anterior', 'name_key' => 'auditora anterior', 'status' => 1]);
        DB::table('shipping_line_branch')->insert([['shipping_line_id' => $id, 'branch_id' => 1], ['shipping_line_id' => $id, 'branch_id' => 2]]);
        DB::table('shipping_line_auditor')->insert(['shipping_line_id' => $id, 'auditor_id' => $auditor]);
        $migration->up();
        $catalog = app(OperationalCatalog::class);
        $this->assertDatabaseCount('shipping_line_branch_auditor', 0);
        $this->assertDatabaseHas('shipping_line_auditor', ['shipping_line_id' => $id, 'auditor_id' => $auditor]);
        $this->assertTrue($catalog->authorizedAuditors($id, 1)->isEmpty());
        $this->get('/catalogos/navieras')->assertOk()->assertSee('Sin sucursal definida');
        $this->get('/catalogos/navieras/'.$id.'/editar')->assertOk()->assertSee('Pendientes de definir sucursal');
        $data = ['name' => 'Naviera anterior', 'branches' => [1, 2], 'auditors' => [$auditor]];
        $this->patchJson('/catalogos/navieras/'.$id, $data)->assertOk();
        $this->assertDatabaseCount('shipping_line_auditor', 1);
        $this->postJson('/catalogos/navieras', array_replace($data, ['name' => 'Nueva naviera']))->assertUnprocessable()->assertJsonValidationErrors('auditors');
        $this->patchJson('/catalogos/navieras/'.$id, $data + ['auditor_assignments' => [2 => [$auditor]]])->assertOk();
        $this->assertDatabaseCount('shipping_line_auditor', 0);
        $this->assertTrue($catalog->authorizedAuditors($id, 1)->isEmpty());
        $this->assertSame([$auditor], $catalog->authorizedAuditors($id, 2)->pluck('id')->all());
        $event = DB::table('operational_catalog_events')->orderByDesc('id')->first();
        $this->assertSame([$auditor], json_decode($event->before_data, true)['auditors']);
        $this->assertSame([2 => [$auditor]], json_decode($event->after_data, true)['auditor_assignments']);
        $this->patchJson('/catalogos/navieras/'.$id, ['name' => 'Naviera anterior', 'branches' => [1]])->assertOk();
        $this->assertDatabaseCount('shipping_line_branch_auditor', 0);
        $this->assertTrue($catalog->authorizedAuditors($id, 2)->isEmpty());
    }

    public function test_parts_and_services_keep_exact_reference_costs_and_searchable_codes(): void
    {
        $this->actingAs($this->user());
        foreach (['repuestos' => 'repair_parts', 'reparaciones' => 'repair_services'] as $catalog => $table) {
            $payload = ['code' => ' qa-01 ', 'name' => 'Registro QA', 'unit' => 'Unidad', 'reference_cost' => '12.50', 'currency' => 'GTQ'];
            $this->post('/catalogos/'.$catalog, $payload)->assertSessionHasNoErrors();
            $id = (int) DB::table($table)->value('id');
            $code = DB::table($table)->where('id', $id)->value('code');
            $this->assertDatabaseHas($table, ['id' => $id, 'reference_cost' => '12.50']);
            $this->get('/catalogos/'.$catalog.'?q='.$code)->assertSee('Registro QA');
            $this->assertStringStartsWith($catalog === 'repuestos' ? 'REP-' : 'RPR-', $code);
            foreach (['-1', '1.234', '999999999', 'no'] as $cost) {
                $this->patch("/catalogos/{$catalog}/{$id}", array_replace($payload, ['reference_cost' => $cost]))->assertSessionHasErrors('reference_cost');
            }
            $this->patch("/catalogos/{$catalog}/{$id}", array_replace($payload, ['reference_cost' => null]))->assertSessionHasNoErrors();
            $this->assertNull(DB::table($table)->where('id', $id)->value('reference_cost'));
        }
    }

    public function test_iso_reference_data_validation_and_html_escaping(): void
    {
        $this->assertDatabaseHas('container_iso_codes', ['code' => '45G1', 'height_mm' => 2896]);
        $this->assertDatabaseCount('container_iso_codes', 3);
        $presets = IsoCodeCatalog::records();
        $this->assertCount(33, $presets);
        $this->assertSame(2438, $presets['20T5']['height_mm']);
        $this->assertSame('Ventilado', $presets['22V0']['type_name']);
        $this->assertSame('Hardtop', $presets['45U6']['type_name']);
        $this->assertSame(6058, $presets['22G1']['length_mm']);
        $this->assertSame(2896, $presets['45R1']['height_mm']);
        $this->actingAs($this->user());
        $this->post('/catalogos/auditoras', ['name' => '<script>alert(1)</script>'])->assertSessionHasNoErrors();
        $this->get('/catalogos/auditoras')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->post('/catalogos/navieras', ['name' => 'Sin sucursal'])->assertSessionHasErrors('branches');
        $this->post('/catalogos/navieras', ['name' => 'Ids invalidos', 'branches' => [999], 'auditors' => [999]])->assertSessionHasErrors(['branches.0', 'auditors.0']);
    }

    public function test_iso_catalog_is_internal_and_manual_changes_are_blocked(): void
    {
        foreach ([1, 2] as $role) {
            $this->actingAs($this->user($role));
            foreach (['/catalogos/iso', '/catalogos/iso/nuevo', '/catalogos/iso/1/editar'] as $url) {
                $this->get($url)->assertRedirect('/ingresos');
            }
            $this->postJson('/catalogos/iso', ['code' => '22R1'])->assertForbidden();
            $this->patchJson('/catalogos/iso/1', ['code' => '45R1'])->assertForbidden();
            $this->patchJson('/catalogos/iso/1/estado', ['status' => 2])->assertForbidden();
            $this->get('/catalogos/navieras')->assertOk()->assertDontSee('href="'.route('catalogs.index', 'iso').'"', false);
            $this->get('/catalogos/repuestos')->assertOk()->assertDontSee('href="'.route('catalogs.index', 'iso').'"', false);
        }
        $this->assertDatabaseCount('container_iso_codes', 3);
        $this->assertDatabaseHas('container_iso_codes', ['id' => 1, 'code' => '22G1', 'status' => 1]);
        $this->assertDatabaseCount('operational_catalog_events', 0);
    }

    public function test_catalog_overview_is_separate_from_parameters_and_old_links_redirect(): void
    {
        $this->get('/catalogos')->assertRedirect('/login');
        foreach ([3, 4, 5] as $role) {
            $this->actingAs($this->user($role))->get('/catalogos')->assertForbidden();
        }
        $this->actingAs($this->user())->withSession(['sucursal_activa' => 1]);
        $this->get('/catalogos')->assertRedirect('/catalogos/navieras');
        $this->get('/parametros')->assertRedirect('/usuarios');
        $this->get('/catalogos/navieras')->assertOk()
            ->assertSee('data-sidebar-group="catalogs"', false)
            ->assertSee('id="sidebar-catalogs"', false)
            ->assertSee('Auditoras externas')->assertSee('Repuestos')->assertSee('Reparaciones')
            ->assertDontSee('href="'.route('branches.index').'"', false);
        $this->get('/parametros/catalogos/navieras?q=QA')->assertStatus(307)->assertRedirect('/catalogos/navieras?q=QA');
        $this->get('/parametros/catalogos/iso/1/editar')->assertStatus(307)->assertRedirect('/catalogos/iso/1/editar');
    }

    public function test_predefined_iso_catalog_is_idempotent_and_preserves_existing_records(): void
    {
        DB::table('container_iso_codes')->where('code', '22G1')->update(['name' => 'Nombre conservado', 'status' => 2, 'description' => 'Nota conservada']);
        $this->seed(IsoCodeCatalogSeeder::class);
        $this->seed(IsoCodeCatalogSeeder::class);
        $this->assertDatabaseCount('container_iso_codes', 33);
        $this->assertDatabaseHas('container_iso_codes', ['code' => '22G1', 'name' => 'Nombre conservado', 'status' => 2, 'description' => 'Nota conservada']);
        $this->assertDatabaseHas('container_iso_codes', ['code' => 'L5G1', 'length_mm' => 13716, 'height_mm' => 2896]);
        $this->assertDatabaseHas('container_iso_codes', ['code' => '45R1', 'type_name' => 'Refrigerado']);
        $this->actingAs($this->user());
        $this->get('/catalogos/iso')->assertRedirect('/ingresos');
        $this->postJson('/catalogos/iso', ['code' => 'ZZZZ', 'name' => 'Inventado'])->assertForbidden();
    }

    private function user(int $role = 2): User
    {
        $user = new User(['username' => 'Catalog QA', 'rol_id' => $role, 'sucursal_id' => 1, 'estado_id' => 1]);
        $user->id = 77;
        $user->exists = true;

        return $user;
    }
}
