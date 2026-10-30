<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UnitTypeManagementInterfaceTest extends TestCase
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
            $table->integer('estado_id');
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nombre');
            $table->integer('estado_id');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('email');
            $table->string('password');
            $table->integer('rol_id');
            $table->integer('sucursal_id');
            $table->integer('estado_id');
            $table->timestamps();
        });
        Schema::create('tipos_unidad', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->integer('estado_id');
        });
        Schema::create('modelos', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nombre');
            $table->integer('tipo_unidad_id');
            $table->integer('estado_id');
        });
        Schema::create('contenedores', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('tipo_unidad_id');
            $table->integer('modelo_id');
            $table->integer('estado_id');
        });
        DB::table('sucursales')->insert(['id' => 1, 'nombre' => 'Sucursal', 'estado_id' => 1]);
        DB::table('roles')->insert(['id' => 1, 'nombre' => 'Super Admin', 'estado_id' => 1]);
        DB::table('users')->insert(['id' => 1, 'username' => 'Super', 'email' => 'super@example.test', 'password' => 'unused', 'rol_id' => 1, 'sucursal_id' => 1, 'estado_id' => 1]);
        DB::table('tipos_unidad')->insert([
            ['id' => 1, 'nombre' => 'CS', 'descripcion' => 'Primero', 'estado_id' => 1],
            ['id' => 2, 'nombre' => 'MAC', 'descripcion' => 'Segundo', 'estado_id' => 1],
            ['id' => 3, 'nombre' => 'Inactivo', 'descripcion' => 'Archivado', 'estado_id' => 2],
        ]);
        DB::table('modelos')->insert([
            ['id' => 1, 'nombre' => 'CS1', 'tipo_unidad_id' => 1, 'estado_id' => 1],
            ['id' => 2, 'nombre' => 'CS2', 'tipo_unidad_id' => 1, 'estado_id' => 1],
            ['id' => 3, 'nombre' => 'MAC1', 'tipo_unidad_id' => 2, 'estado_id' => 1],
            ['id' => 4, 'nombre' => 'Anterior', 'tipo_unidad_id' => 3, 'estado_id' => 2],
        ]);
        DB::table('contenedores')->insert(['tipo_unidad_id' => 1, 'modelo_id' => 1, 'estado_id' => 1]);
        $this->createNotificationTables();
        $this->actingAs(User::first())->withSession(['sucursal_activa' => 1]);
    }

    public function test_live_table_filters_states_forms_and_permissions(): void
    {
        $this->get('/tipos-unidad')->assertOk()->assertSee('data-admin-live-query', false)->assertSee('unitTypeDeactivateDialog')->assertSee('Opciones')->assertDontSee('Tipos activos');
        $this->get('/tipos-unidad?filters[models]=CS1')->assertOk()->assertViewHas('types', fn ($types) => $types->total() === 1);
        $this->get('/tipos-unidad?estado=inactivos')->assertOk()->assertViewHas('types', fn ($types) => $types->total() === 1);
        $this->get('/tipos-unidad?estado=todos')->assertOk()->assertViewHas('types', fn ($types) => $types->total() === 3);
        $this->get('/tipos-unidad/create')->assertOk()->assertSee('data-unit-type-form', false);
        $this->get('/tipos-unidad/3/edit')->assertNotFound();
        $this->get('/tipos-unidad/3/edit?reactivar=1')->assertOk()->assertSee('Reactivar tipo de unidad')->assertSee('Anterior');
        $user = User::first();
        $user->rol_id = 3;
        $this->actingAs($user)->get('/tipos-unidad')->assertForbidden();
    }

    public function test_creation_duplicate_models_and_foreign_model_ids(): void
    {
        $data = ['nombre' => 'Nuevo', 'descripcion' => '', 'modelos_ids' => [0], 'modelos_nombres' => ['Nuevo1']];
        $this->postJson('/tipos-unidad', $data)->assertOk();
        $this->assertDatabaseHas('tipos_unidad', ['nombre' => 'Nuevo', 'estado_id' => 1]);
        $this->postJson('/tipos-unidad', $data)->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->postJson('/tipos-unidad', array_replace($data, ['nombre' => 'Inactivo']))->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->postJson('/tipos-unidad', array_replace($data, ['nombre' => 'Duplicado', 'modelos_nombres' => ['Modelo', 'modelo']]))->assertUnprocessable()->assertJsonValidationErrors('modelos_nombres.1');
        $this->patchJson('/tipos-unidad/1', ['nombre' => 'CS', 'modelos_ids' => [3], 'modelos_nombres' => ['Ajeno']])->assertUnprocessable()->assertJsonValidationErrors('modelos_nombres.0');
        $this->patchJson('/tipos-unidad/1', ['nombre' => 'CS', 'modelos_ids' => [2], 'modelos_nombres' => ['CS2']])->assertUnprocessable()->assertJsonValidationErrors('modelos_nombres');
        $this->assertDatabaseHas('modelos', ['id' => 1, 'estado_id' => 1]);
    }

    public function test_deactivation_is_blocked_in_use_and_reactivation_preserves_models(): void
    {
        $this->deleteJson('/tipos-unidad/1')->assertUnprocessable();
        $this->assertDatabaseHas('tipos_unidad', ['id' => 1, 'estado_id' => 1]);
        $this->deleteJson('/tipos-unidad/2')->assertOk();
        $this->assertDatabaseHas('modelos', ['id' => 3, 'estado_id' => 2]);
        $this->patchJson('/tipos-unidad/2', ['nombre' => 'MAC', 'reactivate' => 1, 'modelos_ids' => [3], 'modelos_nombres' => ['MAC1']])->assertOk()->assertJsonPath('message', 'Tipo de unidad reactivado correctamente.');
        $this->assertDatabaseHas('tipos_unidad', ['id' => 2, 'estado_id' => 1]);
        $this->assertDatabaseHas('modelos', ['id' => 3, 'nombre' => 'MAC1', 'estado_id' => 1]);
        $this->assertDatabaseCount('modelos', 4);
    }

    public function test_description_accepts_255_characters_and_rejects_256(): void
    {
        $data = ['nombre' => 'Limite', 'descripcion' => str_repeat('á', 255), 'modelos_nombres' => ['Modelo']];
        $this->postJson('/tipos-unidad', $data)->assertOk();
        $id = DB::table('tipos_unidad')->where('nombre', 'Limite')->value('id');
        $this->postJson('/tipos-unidad', array_replace($data, ['nombre' => 'Otro', 'descripcion' => str_repeat('a', 256)]))->assertUnprocessable()->assertJsonValidationErrors('descripcion');
        $this->patchJson('/tipos-unidad/'.$id, array_replace($data, ['descripcion' => str_repeat('a', 256)]))->assertUnprocessable()->assertJsonValidationErrors('descripcion');
        $this->assertDatabaseHas('tipos_unidad', ['id' => $id, 'descripcion' => $data['descripcion']]);
    }
}
