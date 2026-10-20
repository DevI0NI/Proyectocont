<?php

namespace Tests\Feature;

use App\Livewire\Containers\InventoryTable;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Shell\BranchSwitcher;
use App\Models\User;
use App\Services\ExternalAuditAccess;
use App\Services\PypAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class ExternalAuditAccessTest extends UserManagementInterfaceTest
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('external_audit_companies')->insert([
            ['id' => 1, 'name' => 'Auditora A', 'status' => 1],
            ['id' => 2, 'name' => 'Auditora B', 'status' => 1],
            ['id' => 3, 'name' => 'Auditora inactiva', 'status' => 2],
        ]);
        DB::table('shipping_lines')->insert([
            ['id' => 1, 'name' => 'Naviera A', 'status' => 1],
            ['id' => 2, 'name' => 'Naviera B', 'status' => 1],
        ]);
        DB::table('shipping_line_branch_auditor')->insert([
            ['shipping_line_id' => 1, 'branch_id' => 1, 'auditor_id' => 1],
            ['shipping_line_id' => 2, 'branch_id' => 2, 'auditor_id' => 1],
            ['shipping_line_id' => 2, 'branch_id' => 2, 'auditor_id' => 2],
        ]);
        Schema::create('patios', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nombre');
            $table->integer('sucursal_id');
        });
        Schema::create('bloques', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('patio_id');
            $table->integer('numero');
        });
        Schema::create('contenedores', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nombre');
            $table->integer('bloque_id');
            $table->integer('posicion');
            $table->integer('estado_id');
            $table->integer('estatus_ipsa_id');
        });
        Schema::create('pyp_auditoria', function (Blueprint $table): void {
            $table->integer('contenedor_id');
            $table->unsignedBigInteger('external_audit_company_id')->nullable();
            $table->string('estatus_pyp');
            $table->string('informacion_adicional')->nullable();
            $table->dateTime('fecha_registro')->nullable();
            $table->integer('usuario_id');
            $table->integer('estado_id');
        });
        Schema::create('logs_eventos', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('contenedor_id');
            $table->integer('usuario_id');
            $table->integer('tipo_operacion_id');
            $table->string('observaciones');
            $table->integer('estado_id');
        });
        Schema::create('maintenance_jobs', function (Blueprint $table): void {
            $table->id();
            $table->integer('container_id');
            $table->unsignedBigInteger('ingress_cycle_id')->nullable();
            $table->dateTime('legacy_ingress_at')->nullable();
            $table->string('status');
        });
        DB::table('patios')->insert(['id' => 1, 'nombre' => 'Patio', 'sucursal_id' => 1]);
        (require database_path('migrations/2026_10_17_173713_add_ingress_audit_assignments.php'))->up();
        DB::table('bloques')->insert(['id' => 1, 'patio_id' => 1, 'numero' => 1]);
        foreach ([1 => 1, 2 => 2, 3 => null] as $container => $company) {
            DB::table('contenedores')->insert(['id' => $container, 'nombre' => 'Equipo '.$container, 'bloque_id' => 1, 'posicion' => $container, 'estado_id' => 1, 'estatus_ipsa_id' => 1]);
            DB::table('pyp_auditoria')->insert(['contenedor_id' => $container, 'external_audit_company_id' => $company, 'estatus_pyp' => 'Asignado', 'usuario_id' => 1, 'estado_id' => 1]);
        }
    }

    public function test_external_configuration_validates_company_branches_and_primary_branch(): void
    {
        $this->actingAs(User::where('username', 'Super')->first());
        $payload = $this->externalPayload();
        $this->postJson('/usuarios', array_diff_key($payload, array_flip(['external_audit_company_id', 'external_branches', 'external_access_level'])))
            ->assertUnprocessable()->assertJsonValidationErrors(['external_audit_company_id', 'external_branches', 'external_access_level']);
        foreach ([['external_audit_company_id' => 3], ['external_branches' => [3]], ['external_branches' => [1, 1]], ['external_access_level' => 'admin']] as $invalid) {
            $this->postJson('/usuarios', array_replace($payload, $invalid))->assertUnprocessable();
        }
        $this->postJson('/usuarios', $payload)->assertOk();
        $user = User::where('username', 'Externo')->firstOrFail();
        $this->assertSame(1, $user->external_audit_company_id);
        $this->assertEqualsCanonicalizing([1, 2], $user->authorizedExternalBranches()->pluck('sucursales.id')->all());
        $this->patchJson(route('users.update', $user), array_replace($payload, ['external_branches' => [1], 'external_access_level' => 'supervisor']))->assertOk();
        $this->assertSame('supervisor', $user->fresh()->external_access_level);
        $this->assertSame([1], $user->authorizedExternalBranches()->pluck('sucursales.id')->all());
        $this->actingAs(User::where('username', 'Admin')->first());
        $this->patchJson(route('users.update', $user), $payload)->assertForbidden();
        $this->actingAs(User::where('username', 'Super')->first());
        $this->patchJson(route('users.update', $user), array_replace($payload, ['rol_id' => 3]))->assertOk();
        $this->assertNull($user->fresh()->external_audit_company_id);
        $this->assertSame(0, $user->authorizedExternalBranches()->count());
    }

    public function test_external_company_scope_does_not_expose_other_companies_or_legacy_assignments(): void
    {
        $user = $this->externalUser();
        $query = DB::table('contenedores as c')->join('pyp_auditoria as pa', 'pa.contenedor_id', '=', 'c.id');
        $this->assertSame([1], app(ExternalAuditAccess::class)->scope($query, $user, 1)->pluck('c.id')->all());
        $this->actingAs($user)->withSession(['sucursal_activa' => 1]);
        $this->get('/contenedores/2/pyp')->assertRedirect();
        $this->patch('/contenedores/2/pyp', ['estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'Intento ajeno'])->assertSessionHasErrors('pyp');
        $this->assertSame('Asignado', DB::table('pyp_auditoria')->where('contenedor_id', 2)->value('estatus_pyp'));
        $this->post('/contenedores/3/pyp/asignar')->assertForbidden();
        foreach (['/reportes', '/reportes/generar_inventario.php', '/usuarios', '/catalogos/auditoras', '/ingresos', '/traslados', '/despachos', '/contenedores/1/editar'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/')->assertRedirect(route('pyp-audits.index'));
        $this->get('/contenedores')->assertRedirect(route('pyp-audits.index'));
    }

    public function test_external_initial_branch_is_derived_without_a_second_branch_selection(): void
    {
        $this->actingAs(User::where('username', 'Super')->first());
        $payload = $this->externalPayload();
        unset($payload['sucursal_id']);
        $payload['external_branches'] = [2];
        $this->postJson('/usuarios', $payload)->assertOk();
        $user = User::where('username', 'Externo')->firstOrFail();
        $this->assertSame(2, $user->sucursal_id);
        $this->patchJson(route('users.update', $user), array_replace($payload, ['sucursal_id' => 99999, 'external_branches' => [1, 2]]))->assertOk();
        $this->assertSame(2, $user->fresh()->sucursal_id);
        $this->patchJson(route('users.update', $user), array_replace($payload, ['external_branches' => [1]]))->assertOk();
        $this->assertSame(1, $user->fresh()->sucursal_id);
        $this->patchJson(route('users.update', $user), array_replace($payload, ['rol_id' => 3]))
            ->assertUnprocessable()->assertJsonValidationErrors('sucursal_id');
    }

    public function test_authorizable_branches_are_the_union_of_active_shipping_line_authorizations(): void
    {
        $access = app(ExternalAuditAccess::class);
        $this->assertEqualsCanonicalizing([1, 2], $access->eligibleBranchMap()[1]);
        $this->assertSame([2], $access->eligibleBranchMap()[2]);
        DB::table('shipping_line_branch_auditor')->insert(['shipping_line_id' => 2, 'branch_id' => 1, 'auditor_id' => 1]);
        $this->assertCount(2, $access->eligibleBranchMap()[1]);
        $this->actingAs(User::where('username', 'Super')->first());
        $this->postJson('/usuarios', array_replace($this->externalPayload(), ['external_audit_company_id' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors('external_branches.0');
        $this->postJson('/usuarios', array_replace($this->externalPayload(), ['external_audit_company_id' => 2, 'external_branches' => [2], 'sucursal_id' => 2]))->assertOk();
        DB::table('shipping_lines')->where('id', 2)->update(['status' => 2]);
        $this->assertSame([1], $access->eligibleBranchMap()[1]);
        $this->assertArrayNotHasKey(2, $access->eligibleBranchMap());
        $this->postJson('/usuarios', array_replace($this->externalPayload('SinContrato'), ['external_audit_company_id' => 2, 'external_branches' => [2], 'sucursal_id' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors('external_branches.0');
        DB::table('sucursales')->where('id', 1)->update(['estado_id' => 2]);
        $this->assertSame([], $access->eligibleBranchMap());
    }

    public function test_inspectors_in_the_same_company_share_access_and_keep_real_authorship(): void
    {
        $first = $this->externalUser();
        $second = $this->externalUser('Companero');
        app(PypAuditService::class)->update($first, 1, 1, ['estatus_pyp' => 'Rechazado', 'informacion_adicional' => 'Falla']);
        app(PypAuditService::class)->update($second, 1, 1, ['estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'Reinspeccion']);
        $this->assertSame([$first->id, $second->id], DB::table('logs_eventos')->orderBy('id')->pluck('usuario_id')->all());
        $this->assertSame(1, DB::table('pyp_auditoria')->where('contenedor_id', 1)->value('external_audit_company_id'));
    }

    public function test_branch_switching_and_livewire_cannot_bypass_external_authorizations(): void
    {
        $user = $this->externalUser();
        $this->actingAs($user)->withSession(['sucursal_activa' => 1]);
        $this->post('/sucursal-activa', ['sucursal_id' => 2])->assertForbidden();
        Livewire::test(BranchSwitcher::class)->call('selectBranch', 2)->assertForbidden();
        $user->authorizedExternalBranches()->attach(2);
        $this->post('/sucursal-activa', ['sucursal_id' => 2])->assertRedirect()->assertSessionHas('sucursal_activa', 2);
        $user->authorizedExternalBranches()->detach(2);
        Livewire::test(InventoryTable::class)->assertForbidden();
        $this->withSession(['sucursal_activa' => 1]);
        Livewire::test(Overview::class)->assertForbidden();
        DB::table('external_audit_companies')->where('id', 1)->update(['status' => 2]);
        $this->get('/seguimiento-pyp')->assertForbidden();
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_external_branch_switch_works_through_the_real_livewire_endpoint_in_both_directions(): void
    {
        $user = $this->externalUser();
        $user->authorizedExternalBranches()->attach(2);
        $this->actingAs($user)->withSession(['sucursal_activa' => 1]);

        foreach ([2, 1] as $branchId) {
            $component = Livewire::test(BranchSwitcher::class);
            $this->postJson(app('livewire')->getUpdateUri(), [
                'components' => [[
                    'snapshot' => json_encode($component->snapshot),
                    'updates' => (object) [],
                    'calls' => [['path' => '', 'method' => 'selectBranch', 'params' => [$branchId]]],
                ]],
            ], ['X-Livewire' => 'true', 'Referer' => route('pyp-audits.index')])
                ->assertOk()
                ->assertSessionHas('sucursal_activa', $branchId)
                ->assertJsonPath('components.0.effects.redirect', route('pyp-audits.index'));
            $this->assertSame($branchId, $user->fresh()->last_active_branch_id);
        }
    }

    public function test_real_livewire_requests_still_reject_unauthorized_branches_and_internal_pages(): void
    {
        $user = $this->externalUser();
        $this->actingAs($user)->withSession(['sucursal_activa' => 1]);
        $component = Livewire::test(BranchSwitcher::class);

        $this->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => json_encode($component->snapshot),
                'updates' => (object) [],
                'calls' => [['path' => '', 'method' => 'selectBranch', 'params' => [2]]],
            ]],
        ], ['X-Livewire' => 'true', 'Referer' => route('pyp-audits.index')])
            ->assertForbidden()->assertSessionHas('sucursal_activa', 1);
        $this->assertNull($user->fresh()->last_active_branch_id);

        $this->get('/reportes', ['X-Livewire' => 'true'])->assertForbidden();
        $this->get('/usuarios', ['X-Livewire' => 'true'])->assertForbidden();
    }

    public function test_unconfigured_and_deactivated_external_accounts_have_no_access(): void
    {
        $user = $this->externalUser();
        $user->update(['external_audit_company_id' => null]);
        $this->actingAs($user->fresh())->withSession(['sucursal_activa' => 1])->get('/seguimiento-pyp')->assertForbidden();
        $user->update(['external_audit_company_id' => 1, 'estado_id' => 2]);
        $this->actingAs($user->fresh())->get('/seguimiento-pyp')->assertForbidden();
    }

    public function test_external_login_chooses_an_authorized_branch_when_the_primary_was_revoked(): void
    {
        $user = $this->externalUser();
        $user->authorizedExternalBranches()->sync([2]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertRedirect()->assertSessionHas('sucursal_activa', 2);
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertRedirect(route('pyp-audits.index'));
    }

    public function test_external_first_login_uses_alphabetical_order_instead_of_the_assigned_branch_id(): void
    {
        $user = $this->externalUser();
        $user->authorizedExternalBranches()->attach(2);
        DB::table('sucursales')->where('id', 2)->update(['nombre' => 'Almacen A']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertRedirect()->assertSessionHas('sucursal_activa', 2);

        $this->assertSame(2, $user->fresh()->last_active_branch_id);
        $this->assertSame(1, $user->fresh()->sucursal_id);
    }

    public function test_external_branch_preference_survives_logout_and_a_new_login(): void
    {
        $user = $this->externalUser();
        $user->authorizedExternalBranches()->attach(2);
        $this->post('/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertRedirect()->assertSessionHas('sucursal_activa', 1);
        $this->post('/sucursal-activa', ['sucursal_id' => 2])
            ->assertRedirect()->assertSessionHas('sucursal_activa', 2);
        $this->assertSame(2, $user->fresh()->last_active_branch_id);
        $this->post('/logout')->assertRedirect('/login');

        $this->post('/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertRedirect()->assertSessionHas('sucursal_activa', 2);
    }

    public function test_external_login_replaces_a_revoked_or_inactive_branch_preference(): void
    {
        $user = $this->externalUser();
        $user->forceFill(['last_active_branch_id' => 2])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertRedirect()->assertSessionHas('sucursal_activa', 1);
        $this->assertSame(1, $user->fresh()->last_active_branch_id);
        $this->post('/logout')->assertRedirect('/login');

        $user->authorizedExternalBranches()->attach(2);
        $user->forceFill(['last_active_branch_id' => 2])->save();
        DB::table('sucursales')->where('id', 2)->update(['estado_id' => 2]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertRedirect()->assertSessionHas('sucursal_activa', 1);
        $this->assertSame(1, $user->fresh()->last_active_branch_id);
    }

    public function test_external_login_without_authorized_branches_does_not_reuse_a_saved_preference(): void
    {
        $user = $this->externalUser();
        $user->forceFill(['last_active_branch_id' => 1])->save();
        $user->authorizedExternalBranches()->detach();

        $this->post('/login', ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertRedirect()->assertSessionHas('sucursal_activa', 0);
        $this->assertNull($user->fresh()->last_active_branch_id);
        $this->get('/seguimiento-pyp')->assertForbidden();
    }

    private function externalPayload(string $name = 'Externo'): array
    {
        return ['username' => $name, 'email' => strtolower($name).'@example.test', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'rol_id' => 5, 'sucursal_id' => 1, 'external_audit_company_id' => 1, 'external_access_level' => 'inspector', 'external_branches' => [1, 2]];
    }

    private function externalUser(string $name = 'Externo'): User
    {
        $payload = $this->externalPayload($name);
        unset($payload['password_confirmation'], $payload['external_branches']);
        $payload['password'] = Hash::make($payload['password']);
        $user = User::create($payload + ['estado_id' => 1]);
        $user->authorizedExternalBranches()->sync([1]);

        return $user;
    }
}
