<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class YardLayoutManagementInterfaceTest extends TestCase
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
        Schema::create('patios', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nombre');
            $table->integer('sucursal_id');
            $table->integer('estado_id');
        });
        Schema::create('bloques', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('numero');
            $table->integer('patio_id');
            $table->integer('capacidad_maxima');
            $table->integer('capacidad_actual');
            $table->integer('estado_id');
        });
        Schema::create('contenedores', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('bloque_id');
            $table->integer('estado_id');
        });
        DB::table('sucursales')->insert([['id' => 1, 'nombre' => 'Principal', 'estado_id' => 1], ['id' => 2, 'nombre' => 'Otra', 'estado_id' => 1]]);
        DB::table('roles')->insert(['id' => 1, 'nombre' => 'Super Admin', 'estado_id' => 1]);
        DB::table('users')->insert(['id' => 1, 'username' => 'Super', 'email' => 'super@example.test', 'password' => 'unused', 'rol_id' => 1, 'sucursal_id' => 1, 'estado_id' => 1]);
        DB::table('patios')->insert([
            ['id' => 1, 'nombre' => 'Patio principal', 'sucursal_id' => 1, 'estado_id' => 1],
            ['id' => 2, 'nombre' => 'Patio vacio', 'sucursal_id' => 1, 'estado_id' => 1],
            ['id' => 3, 'nombre' => 'Ajeno', 'sucursal_id' => 2, 'estado_id' => 1],
        ]);
        DB::table('bloques')->insert([
            ['id' => 1, 'numero' => 1, 'patio_id' => 1, 'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1],
            ['id' => 2, 'numero' => 2, 'patio_id' => 1, 'capacidad_maxima' => 10, 'capacidad_actual' => 0, 'estado_id' => 1],
            ['id' => 3, 'numero' => 1, 'patio_id' => 3, 'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1],
        ]);
        for ($i = 0; $i < 3; $i++) {
            DB::table('contenedores')->insert(['bloque_id' => 1, 'estado_id' => 1]);
        }
        $this->actingAs(User::first())->withSession(['sucursal_activa' => 1]);
    }

    public function test_workspace_filters_pagination_and_unfiltered_summary(): void
    {
        $this->get('/patios-bloques')->assertOk()->assertSee('yardEditorDialog')->assertSee('data-admin-live-query', false)->assertSee('Bloques</a>', false)->assertViewHas('patios', fn ($patios) => $patios->total() === 2 && (int) $patios->first()->ocupacion_total === 3);
        $this->get('/patios-bloques?filters[blocks]=2')->assertOk()->assertViewHas('patios', fn ($patios) => $patios->total() === 1);
        $this->get('/patios-bloques/patios/1/bloques')->assertOk()->assertSee('Volver a patios')->assertViewHas('blocks', fn ($blocks) => $blocks->total() === 2)->assertViewHas('summary', fn ($summary) => (int) $summary->capacity === 35 && (int) $summary->occupied === 3);
        $this->get('/patios-bloques/patios/1/bloques?filters[occupancy]=3')->assertOk()->assertViewHas('blocks', fn ($blocks) => $blocks->total() === 1 && $blocks->first()->id === 1)->assertViewHas('summary', fn ($summary) => (int) $summary->capacity === 35);
        $this->get('/patios-bloques/patios/1/bloques?sort=occupancy&order=desc&per_page=5')->assertOk()->assertViewHas('blocks', fn ($blocks) => $blocks->perPage() === 5 && $blocks->first()->id === 1);
        $this->get('/patios-bloques/patios/2/bloques')->assertOk()->assertViewHas('blocks', fn ($blocks) => $blocks->total() === 0);
        $this->get('/patios-bloques/patios/3/bloques')->assertForbidden();
        $this->get('/patios-bloques/patios/999/bloques')->assertNotFound();
    }

    public function test_json_mutations_capacity_limits_and_blocked_deactivation(): void
    {
        $this->postJson('/patios-bloques/patios', ['nombre' => str_repeat('a', 256)])->assertUnprocessable();
        $this->postJson('/patios-bloques/patios', ['nombre' => 'Nuevo patio'])->assertOk();
        $this->postJson('/patios-bloques/patios', ['nombre' => 'Nuevo patio'])->assertUnprocessable();
        $this->patchJson('/patios-bloques/patios/1', ['nombre' => 'Nombre actualizado'])->assertOk();
        $this->deleteJson('/patios-bloques/patios/1')->assertUnprocessable()->assertJsonValidationErrors('patio');
        $this->deleteJson('/patios-bloques/bloques/1')->assertUnprocessable()->assertJsonValidationErrors('block');
        $this->patchJson('/patios-bloques/bloques/1', ['capacidad_maxima' => 2])->assertUnprocessable()->assertJsonValidationErrors('capacidad_maxima');
        $this->patchJson('/patios-bloques/bloques/1', ['capacidad_maxima' => 26])->assertUnprocessable();
        $this->postJson('/patios-bloques/bloques', ['patio_id' => 1, 'capacidad_maxima' => 0])->assertUnprocessable();
        $this->postJson('/patios-bloques/bloques', ['patio_id' => 1, 'capacidad_maxima' => 25])->assertOk();
        $this->assertDatabaseHas('bloques', ['patio_id' => 1, 'numero' => 3, 'capacidad_maxima' => 25]);
        $this->deleteJson('/patios-bloques/bloques/2')->assertOk();
        $this->deleteJson('/patios-bloques/patios/2')->assertOk();
        $this->assertDatabaseHas('bloques', ['id' => 2, 'estado_id' => 2]);
        $this->assertDatabaseHas('patios', ['id' => 2, 'estado_id' => 2]);
    }

    public function test_branch_and_role_boundaries_remain_enforced(): void
    {
        $this->patchJson('/patios-bloques/patios/3', ['nombre' => 'Ajeno editado'])->assertForbidden();
        $this->deleteJson('/patios-bloques/patios/3')->assertForbidden();
        $this->patchJson('/patios-bloques/bloques/3', ['capacidad_maxima' => 10])->assertForbidden();
        $this->deleteJson('/patios-bloques/bloques/3')->assertForbidden();
        $this->postJson('/patios-bloques/bloques', ['patio_id' => 3, 'capacidad_maxima' => 10])->assertForbidden();
        $user = User::first();
        $user->rol_id = 3;
        $this->actingAs($user)->get('/patios-bloques')->assertForbidden();
    }
}
