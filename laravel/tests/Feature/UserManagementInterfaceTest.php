<?php

namespace Tests\Feature;

use App\Jobs\SendAccountCredential;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserManagementInterfaceTest extends TestCase
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
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->integer('rol_id');
            $table->integer('sucursal_id');
            $table->integer('last_active_branch_id')->nullable();
            $table->integer('estado_id');
            $table->timestamps();
            $table->json('permissions')->nullable();
            $table->unsignedBigInteger('external_audit_company_id')->nullable();
            $table->string('external_access_level', 20)->nullable();
        });
        (require database_path('migrations/2026_10_28_154733_create_account_recovery_tables.php'))->up();
        config(['account-recovery.enabled' => true, 'cache.default' => 'array']);
        Queue::fake([SendAccountCredential::class]);
        Schema::create('external_audit_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('status');
        });
        Schema::create('external_audit_user_branch', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('branch_id');
            $table->primary(['user_id', 'branch_id']);
        });
        Schema::create('shipping_lines', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('status');
        });
        Schema::create('shipping_line_branch_auditor', function (Blueprint $table): void {
            $table->unsignedBigInteger('shipping_line_id');
            $table->integer('branch_id');
            $table->unsignedBigInteger('auditor_id');
        });
        DB::table('sucursales')->insert([['id' => 1, 'nombre' => 'Sucursal A', 'estado_id' => 1], ['id' => 2, 'nombre' => 'Sucursal B', 'estado_id' => 1], ['id' => 3, 'nombre' => 'Sucursal inactiva', 'estado_id' => 2]]);
        foreach ([1 => 'Super Admin', 2 => 'Admin', 3 => 'Usuario', 4 => 'Mecanico', 5 => 'Empresa Externa'] as $id => $name) {
            DB::table('roles')->insert(['id' => $id, 'nombre' => $name, 'estado_id' => 1]);
        }
        $this->user(1, 1, 'Super');
        $this->user(2, 1, 'Admin');
        $this->user(3, 1, 'Local');
        $this->user(4, 2, 'Otro');
        $this->user(3, 1, 'Inactivo', 2);
        $this->withSession(['sucursal_activa' => 1]);
        foreach (['2026_10_20_170939_create_system_notifications_table.php', '2026_10_20_172530_add_personal_folders_to_system_notifications.php',
            '2026_10_20_175848_add_expiry_to_system_notifications.php', '2026_10_21_052602_add_activity_details_to_system_notifications.php',
            '2026_10_21_060157_add_bell_dismissed_at_to_system_notifications.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }

    public function test_table_filters_and_states_respect_branch_administrator_visibility(): void
    {
        $this->actingAs(User::where('username', 'Super')->first());
        $this->get('/usuarios')->assertOk()->assertDontSee('userFormDialog')->assertSee('userDeactivateDialog')->assertSee('Opciones');
        $this->get(route('users.create'))->assertOk()->assertSee('data-user-standalone', false)->assertSee('Nuevo usuario');
        $this->get(route('users.edit', User::where('username', 'Local')->first()))->assertOk()->assertSee('data-user-standalone', false)->assertSee('Editar usuario');
        $this->get('/usuarios?estado=inactivos')->assertOk()->assertViewHas('users', fn ($users) => $users->total() === 1);
        $this->get('/usuarios?estado=todos')->assertOk()->assertViewHas('users', fn ($users) => $users->total() === 5);
        $this->get('/usuarios?filters[role]=Mecanico&sort=email&order=desc')->assertOk()->assertViewHas('users', fn ($users) => $users->total() === 1 && $users->first()->username === 'Otro');
        $this->actingAs(User::where('username', 'Admin')->first());
        $this->get('/usuarios')->assertOk()->assertViewHas('users', fn ($users) => $users->total() === 1 && $users->first()->username === 'Local');
        $this->get('/usuarios?estado=inactivos')->assertForbidden();
        $this->get('/usuarios?estado=todos')->assertForbidden();
        $this->get('/usuarios?q=Otro')->assertOk()->assertViewHas('users', fn ($users) => $users->total() === 0);
    }

    public function test_json_editor_excludes_password_and_preserves_existing_super_admin_role(): void
    {
        $super = User::where('username', 'Super')->first();
        $other = User::where('username', 'Otro')->first();
        $this->actingAs($super);
        $this->getJson('/usuarios/'.$super->id.'/edit')->assertOk()->assertJsonMissingPath('password')->assertJsonPath('rol_id', 1)->assertJsonPath('roles.0.id', 1);
        $hash = $super->password;
        $this->patchJson('/usuarios/'.$super->id, array_replace($this->payload('Super', 1, 1), ['password' => '']))->assertOk()->assertJsonPath('refreshShell', true);
        $this->assertSame($hash, $super->fresh()->password);
        $this->assertSame(1, $super->fresh()->rol_id);
        $this->actingAs(User::where('username', 'Admin')->first());
        $this->getJson('/usuarios/'.$super->id.'/edit')->assertForbidden();
        $this->getJson('/usuarios/'.$other->id.'/edit')->assertForbidden();
        $this->patchJson('/usuarios/'.$other->id, $this->payload('Otro', 3, 1))->assertForbidden();
    }

    public function test_json_creation_editing_reactivation_and_self_deactivation_rules(): void
    {
        $super = User::where('username', 'Super')->first();
        $this->actingAs($super);
        $this->postJson('/usuarios', $this->payload('Nuevo'))->assertOk()->assertJsonPath('message', 'Usuario creado correctamente.');
        $user = User::where('username', 'Nuevo')->firstOrFail();
        $this->assertFalse(Hash::check('Secret123!', $user->password));
        $this->assertTrue($user->requires_password_setup);
        Queue::assertPushed(SendAccountCredential::class, fn ($job) => $job->userId === $user->id && $job->purpose === 'activation');
        $this->postJson('/usuarios', $this->payload('Nuevo'))->assertUnprocessable()->assertJsonValidationErrors(['username', 'email']);
        $this->postJson('/usuarios', $this->payload('Inactivo'))->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->patchJson('/usuarios/'.$user->id, $this->payload('Nuevo', 4, 2))->assertOk();
        $this->assertSame(2, $user->fresh()->sucursal_id);
        $this->deleteJson('/usuarios/'.$super->id)->assertUnprocessable();
        $this->assertSame(1, $super->fresh()->estado_id);
        $this->deleteJson('/usuarios/'.$user->id)->assertOk();
        $this->assertSame(2, $user->fresh()->estado_id);
        $data = $this->payload('Nuevo', 3, 1) + ['reactivate' => 1];
        $this->patchJson('/usuarios/'.$user->id, array_replace($data, ['password' => 'Manual123!']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->patchJson('/usuarios/'.$user->id, $data)->assertOk()->assertJsonPath('message', 'Usuario reactivado correctamente.');
        $this->assertSame(1, $user->fresh()->estado_id);
        $this->postJson('/usuarios', $this->payload('Elevado', 1, 1))->assertUnprocessable()->assertJsonValidationErrors('rol_id');
        $this->postJson('/usuarios', $this->payload('SinSede', 3, 3))->assertUnprocessable()->assertJsonValidationErrors('sucursal_id');
        $this->actingAs(User::where('username', 'Admin')->first());
        $this->postJson('/usuarios', $this->payload('Ajeno', 3, 2))->assertUnprocessable()->assertJsonValidationErrors('sucursal_id');
        $this->patchJson('/usuarios/'.$user->id, $data)->assertForbidden();
        $this->postJson('/usuarios', $this->payload('Elevado', 2, 1))->assertUnprocessable()->assertJsonValidationErrors('rol_id');
    }

    private function payload(string $name, int $role = 3, int $branch = 1): array
    {
        return ['username' => $name, 'email' => strtolower($name).'@example.test', 'rol_id' => $role, 'sucursal_id' => $branch];
    }

    public function test_manual_password_assignment_is_prohibited_and_email_availability_is_checked(): void
    {
        $this->actingAs(User::where('username', 'Super')->first());
        foreach (['Ab1!', 'secret123!', 'Secretabc!', 'Secret123'] as $password) {
            $this->postJson('/usuarios', array_replace($this->payload('Nuevo'), ['password' => $password, 'password_confirmation' => $password]))->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/usuarios', array_replace($this->payload('Nuevo'), ['password_confirmation' => 'Different123!']))->assertUnprocessable()->assertJsonValidationErrors('password_confirmation');
        $this->postJson('/usuarios', array_replace($this->payload('Nuevo'), ['email' => 'correo-invalido']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(route('users.check'), ['username' => 'Local'])->assertOk()->assertJsonPath('status', 'exists');
        $this->postJson(route('users.check'), ['email' => 'inactivo@example.test'])->assertOk()->assertJsonPath('status', 'inactive');
        $local = User::where('username', 'Local')->first();
        $this->postJson(route('users.check'), ['username' => 'Local', 'id' => $local->id])->assertOk()->assertJsonPath('status', 'available');
        $this->postJson(route('users.check'), ['email' => 'bad'])->assertUnprocessable();
        $this->actingAs(User::where('username', 'Admin')->first());
        $this->postJson(route('users.check'), ['username' => 'Super', 'id' => User::where('username', 'Super')->value('id')])->assertForbidden();
    }

    public function test_super_admin_can_assign_and_reset_permissions_with_view_dependencies(): void
    {
        $super = User::where('username', 'Super')->first();
        $target = User::where('username', 'Local')->first();
        $this->actingAs($super);
        $this->patchJson('/usuarios/'.$target->id, $this->payload('Local') + ['permission_mode' => 'custom', 'permissions' => ['users.manage']])->assertOk();
        $this->assertEqualsCanonicalizing(['users.manage', 'users.view'], $target->fresh()->permissions);
        $this->actingAs($target->fresh());
        $this->get('/usuarios')->assertOk()->assertSee('Nuevo usuario')->assertSee('Sucursal A');
        $this->get('/reportes')->assertForbidden();
        $this->post('/traslados', [])->assertForbidden();
        $this->get('/usuarios/'.User::where('username', 'Admin')->value('id').'/edit')->assertForbidden();
        $this->actingAs($super);
        $this->patchJson('/usuarios/'.$target->id, $this->payload('Local') + ['permission_mode' => 'role'])->assertOk();
        $this->assertNull($target->fresh()->permissions);
        $this->assertTrue($target->fresh()->hasPermission('containers.edit'));
    }

    public function test_read_only_users_see_no_management_actions_and_direct_writes_are_denied(): void
    {
        $admin = User::where('username', 'Admin')->first();
        $admin->update(['permissions' => ['users.view']]);
        $this->actingAs($admin);
        $response = $this->get('/usuarios')->assertOk()->assertSee('Local');
        $response->assertDontSee('href="'.route('users.create').'"', false)
            ->assertDontSee('data-user-deactivate data-action', false)
            ->assertDontSee('href="'.route('reports.index').'"', false);
        $this->get('/usuarios/create')->assertForbidden();
        $this->get('/usuarios/'.User::where('username', 'Local')->value('id').'/edit')->assertForbidden();
        $this->postJson('/usuarios', $this->payload('Prohibido'))->assertForbidden();
        $this->post('/parametros/usuarios/verificar_usuario.php', ['username' => 'Nombre'])->assertForbidden();
        $this->get('/reportes/generar_inventario.php')->assertForbidden();
    }

    public function test_only_super_admin_can_customize_internal_permissions_and_fixed_roles_reject_them(): void
    {
        $target = User::where('username', 'Local')->first();
        $this->actingAs(User::where('username', 'Admin')->first());
        $this->patchJson('/usuarios/'.$target->id, $this->payload('Local') + ['permission_mode' => 'custom', 'permissions' => ['users.manage']])->assertForbidden();
        $this->assertNull($target->fresh()->permissions);
        $this->actingAs(User::where('username', 'Super')->first());
        $this->patchJson('/usuarios/'.$target->id, $this->payload('Local') + ['permission_mode' => 'custom', 'permissions' => ['inventado.manage']])->assertUnprocessable();
        $this->patchJson('/usuarios/'.$target->id, array_replace($this->payload('Local'), ['rol_id' => 4, 'permission_mode' => 'custom', 'permissions' => ['users.manage']]))->assertUnprocessable();
        $this->assertSame(3, $target->fresh()->rol_id);
        $mechanic = User::where('username', 'Otro')->first();
        $mechanic->update(['permissions' => ['users.manage', 'users.view']]);
        $this->assertFalse($mechanic->fresh()->hasPermission('users.manage'));
        $this->actingAs($mechanic->fresh())->get('/usuarios')->assertForbidden();
    }

    public function test_empty_permissions_are_distinct_from_inheriting_role_and_role_changes_reset_them(): void
    {
        $target = User::where('username', 'Local')->first();
        $this->actingAs(User::where('username', 'Super')->first());
        $this->patchJson('/usuarios/'.$target->id, $this->payload('Local') + ['permission_mode' => 'custom'])->assertOk();
        $this->assertSame([], $target->fresh()->permissions);
        $this->actingAs($target->fresh())->get('/')->assertForbidden();
        $this->get('/contenedores')->assertForbidden();
        $this->get('/usuarios')->assertForbidden();
        $this->actingAs(User::where('username', 'Super')->first());
        $this->patchJson('/usuarios/'.$target->id, array_replace($this->payload('Local'), ['rol_id' => 4]))->assertOk();
        $this->assertNull($target->fresh()->permissions);
        $this->get('/usuarios/'.$target->id.'/edit')->assertOk()->assertSee('Accesos y permisos');
    }

    public function test_super_admin_role_cannot_be_removed_from_another_account_or_yourself(): void
    {
        $actor = User::where('username', 'Super')->firstOrFail();
        $target = $this->user(1, 2, 'OtroSuper');
        $this->actingAs($actor);

        $this->getJson(route('users.edit', $target))->assertOk()->assertJsonCount(1, 'roles')->assertJsonPath('roles.0.id', 1);
        $this->get(route('users.edit', $target))->assertOk()
            ->assertSee('Este rol esta protegido y no se puede cambiar.')
            ->assertDontSee('<select name="rol_id"', false);

        foreach ([2, 3, 4, 5] as $role) {
            $this->patchJson(route('users.update', $target), $this->payload('OtroSuper', $role, 2))
                ->assertUnprocessable()->assertJsonValidationErrors('rol_id');
            $this->assertSame(1, $target->fresh()->rol_id);
        }
        $this->patch(route('users.update', $target), $this->payload('OtroSuper', 3, 2))
            ->assertSessionHasErrors('rol_id');
        $this->patchJson(route('users.update', $actor), $this->payload('Super', 2, 1))
            ->assertUnprocessable()->assertJsonValidationErrors('rol_id');
        $this->assertSame(1, $actor->fresh()->rol_id);

        $this->patchJson(route('users.update', $target), array_replace($this->payload('OtroSuper', 1, 2), ['password' => '']))->assertOk();
        $this->assertSame(1, $target->fresh()->rol_id);
        $this->patchJson(route('users.update', $target), $this->payload('OtroSuper', 1, 2) + ['permission_mode' => 'custom', 'permissions' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');
    }

    public function test_super_admin_accounts_cannot_be_deactivated_or_demoted_during_reactivation(): void
    {
        $actor = User::where('username', 'Super')->firstOrFail();
        $target = $this->user(1, 2, 'OtroSuper');
        $inactive = $this->user(1, 2, 'SuperInactivo', 2);
        $this->actingAs($actor);

        foreach ([$actor, $target] as $account) {
            $this->deleteJson(route('users.destroy', $account))->assertUnprocessable()->assertJsonValidationErrors('delete');
            $this->assertSame(1, $account->fresh()->estado_id);
        }
        $this->get('/usuarios')->assertOk()
            ->assertDontSee('data-user-deactivate data-action="'.route('users.destroy', $target).'"', false);
        $this->patchJson(route('users.update', $inactive), $this->payload('SuperInactivo', 3, 2) + ['reactivate' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('rol_id');
        $this->assertSame(1, $inactive->fresh()->rol_id);
        $this->assertSame(2, $inactive->fresh()->estado_id);
        $this->patchJson(route('users.update', $inactive), $this->payload('SuperInactivo', 1, 2) + ['reactivate' => 1])->assertOk();
        $this->assertSame(1, $inactive->fresh()->estado_id);
    }

    private function user(int $role, int $branch, string $name, int $state = 1): User
    {
        return User::create(array_replace($this->payload($name, $role, $branch), ['password' => Hash::make('Secret123!'), 'estado_id' => $state]));
    }
}
