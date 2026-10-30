<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContainerStatusManagementInterfaceTest extends TestCase
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
        Schema::create('tipos_estatus', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nombre');
            $table->integer('estado_id');
        });
        Schema::create('estatus_contenedores', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nombre');
            $table->integer('tipo_estatus_id');
            $table->integer('estado_id');
        });
        Schema::create('contenedores', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('estatus_unidad_id');
            $table->integer('estatus_box_id');
            $table->integer('estado_id');
        });
        DB::table('sucursales')->insert(['id' => 1, 'nombre' => 'Sucursal', 'estado_id' => 1]);
        DB::table('roles')->insert(['id' => 1, 'nombre' => 'Super Admin', 'estado_id' => 1]);
        DB::table('users')->insert(['id' => 1, 'username' => 'Super', 'email' => 'super@example.test', 'password' => 'unused', 'rol_id' => 1, 'sucursal_id' => 1, 'estado_id' => 1]);
        DB::table('tipos_estatus')->insert([['id' => 1, 'nombre' => 'Unidad', 'estado_id' => 1], ['id' => 2, 'nombre' => 'Estructura', 'estado_id' => 1]]);
        DB::table('estatus_contenedores')->insert([
            ['id' => 1, 'nombre' => 'OK', 'tipo_estatus_id' => 1, 'estado_id' => 1],
            ['id' => 2, 'nombre' => 'DMG', 'tipo_estatus_id' => 1, 'estado_id' => 1],
            ['id' => 3, 'nombre' => 'OK', 'tipo_estatus_id' => 2, 'estado_id' => 1],
            ['id' => 4, 'nombre' => 'Anterior', 'tipo_estatus_id' => 1, 'estado_id' => 2],
        ]);
        DB::table('contenedores')->insert(['estatus_unidad_id' => 1, 'estatus_box_id' => 3, 'estado_id' => 1]);
        $this->createNotificationTables();
        $this->actingAs(User::first())->withSession(['sucursal_activa' => 1]);
    }

    public function test_live_table_categories_states_filters_and_fallback_forms(): void
    {
        $this->get('/estatus')->assertOk()->assertSee('data-admin-live-query', false)->assertSee('statusFormDialog')->assertSee('statusActionDialog')->assertSee('Opciones')->assertViewHas('statuses', fn ($statuses) => $statuses->total() === 2);
        $this->get('/estatus?tipo=Estructura')->assertOk()->assertViewHas('statuses', fn ($statuses) => $statuses->total() === 1 && $statuses->first()->tipo_estatus_id === 2);
        $this->get('/estatus?estado=inactivos')->assertOk()->assertViewHas('statuses', fn ($statuses) => $statuses->total() === 1);
        $this->get('/estatus?estado=todos&filters[name]=Anterior')->assertOk()->assertViewHas('statuses', fn ($statuses) => $statuses->total() === 1);
        $this->get('/estatus/create')->assertOk()->assertSee('data-status-standalone', false);
        $this->get('/estatus/2/edit')->assertOk()->assertSee('DMG');
        $this->get('/estatus/4/edit')->assertNotFound();
        $user = User::first();
        $user->rol_id = 3;
        $this->actingAs($user)->get('/estatus')->assertForbidden();
        $this->patchJson('/estatus/4/reactivar')->assertForbidden();
    }

    public function test_creation_editing_duplicates_and_255_character_limit(): void
    {
        $this->postJson('/estatus', ['nombre' => '', 'tipo_estatus_id' => 99])->assertUnprocessable()->assertJsonValidationErrors(['nombre', 'tipo_estatus_id']);
        $this->postJson('/estatus', ['nombre' => str_repeat('a', 256), 'tipo_estatus_id' => 1])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->postJson('/estatus', ['nombre' => str_repeat('a', 255), 'tipo_estatus_id' => 1])->assertOk();
        $this->postJson('/estatus', ['nombre' => 'OK', 'tipo_estatus_id' => 1])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->patchJson('/estatus/2', ['nombre' => 'DMG actualizado', 'tipo_estatus_id' => 1])->assertOk()->assertJsonPath('type', 'Unidad');
        $this->assertDatabaseHas('estatus_contenedores', ['id' => 2, 'nombre' => 'DMG actualizado']);
        $this->patchJson('/estatus/2', ['nombre' => 'OK', 'tipo_estatus_id' => 1])->assertUnprocessable();
    }

    public function test_blocked_deactivation_and_reactivation_preserve_history(): void
    {
        foreach ([1, 3] as $id) {
            $this->deleteJson('/estatus/'.$id)->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->assertDatabaseHas('estatus_contenedores', ['id' => $id, 'estado_id' => 1]);
        }
        $this->deleteJson('/estatus/2')->assertOk();
        $this->assertDatabaseHas('estatus_contenedores', ['id' => 2, 'estado_id' => 2]);
        $this->patchJson('/estatus/2/reactivar')->assertOk();
        $this->assertDatabaseHas('estatus_contenedores', ['id' => 2, 'estado_id' => 1]);
        $this->patchJson('/estatus/2/reactivar')->assertNotFound();
        $this->postJson('/estatus', ['nombre' => 'Anterior', 'tipo_estatus_id' => 1])->assertOk()->assertJsonPath('message', 'Estatus reactivado correctamente.');
        $this->assertDatabaseCount('estatus_contenedores', 4);
    }
}
