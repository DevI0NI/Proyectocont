<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedMysql();
        DB::beginTransaction();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['account-recovery.enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_seeded_roles_obey_user_management_and_recovery_boundaries(): void
    {
        foreach ([1, 2, 3, 4, 5] as $role) {
            $this->signIn($role);
            $this->get('/usuarios')->assertStatus(in_array($role, [1, 2], true) ? 200 : 403);
            if ($role === 1) {
                $this->get('/notificaciones/recuperacion')->assertRedirect('/notificaciones?category=account-recovery');
            } else {
                $this->get('/notificaciones/recuperacion')->assertForbidden();
            }
        }
    }

    public function test_internal_roles_can_read_their_branch_history_but_not_foreign_containers(): void
    {
        foreach ([1, 2, 3] as $role) {
            $user = $this->signIn($role);
            $own = DB::table('container_ingress_cycles')->where('branch_id', $user->sucursal_id)->value('container_id');
            $foreign = DB::table('container_ingress_cycles')->where('branch_id', '<>', $user->sucursal_id)->value('container_id');
            $this->assertNotNull($own);
            $this->assertNotNull($foreign);
            $this->get('/historial-contenedores/'.$own)->assertOk();
            $this->get('/historial-contenedores/'.$foreign)->assertNotFound();
        }
    }

    public function test_changing_cycle_identifier_cannot_read_another_container_history(): void
    {
        $user = $this->signIn(2);
        $cycle = DB::table('container_ingress_cycles')->where('branch_id', $user->sucursal_id)->first();
        $foreignCycle = DB::table('container_ingress_cycles')->where('container_id', '<>', $cycle->container_id)->value('id');
        $this->get('/historial-contenedores/'.$cycle->container_id.'?cycle='.$foreignCycle)->assertNotFound();
    }

    public function test_history_json_rejects_unbounded_pagination_and_untrusted_sorting(): void
    {
        $this->signIn(2);
        foreach ([['per_page' => 100000], ['sort' => 'id desc; DROP TABLE users'], ['order' => 'random'],
            ['state' => 'anything'], ['search' => str_repeat('a', 61)], ['ingress_from' => 'invalid']] as $query) {
            $this->getJson('/historial-contenedores?'.http_build_query($query))->assertUnprocessable();
        }
    }

    public function test_sql_metacharacters_are_literal_history_search_terms(): void
    {
        $this->signIn(2);
        foreach (["' OR 1=1 --", '%', '_', "'; SELECT SLEEP(1); --"] as $payload) {
            $this->get('/historial-contenedores?'.http_build_query(['search' => $payload]))
                ->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->total() === 0);
        }
    }

    public function test_history_search_does_not_render_unescaped_script_markup(): void
    {
        $this->signIn(2);
        $payload = '<script>alert(1)</script>';
        $this->get('/historial-contenedores?'.http_build_query(['search' => $payload]))
            ->assertOk()->assertDontSee($payload, false);
    }

    public function test_other_users_notifications_cannot_be_read_or_marked_read(): void
    {
        $user = $this->signIn(3);
        $id = DB::table('system_notifications')->insertGetId([
            'user_id' => User::where('rol_id', 1)->value('id'), 'branch_id' => $user->sucursal_id,
            'category' => 'containers', 'audience' => 'internal', 'event_key' => 'security-audit:'.uniqid(),
            'title' => 'Private audit fixture', 'body' => 'Only the recipient may read this notice.',
        ]);
        $this->get('/notificaciones/'.$id.'/detalle')->assertNotFound();
        $this->post('/notificaciones/'.$id.'/leer')->assertNotFound();
        $this->assertNull(DB::table('system_notifications')->where('id', $id)->value('read_at'));
    }

    public function test_operator_cannot_escalate_role_or_approve_password_recovery(): void
    {
        $user = $this->signIn(3);
        $this->patchJson('/usuarios/'.$user->id, ['rol_id' => 1, 'permissions' => ['users.manage' => true]])->assertForbidden();
        $this->postJson('/notificaciones/recuperacion/1', ['decision' => 'approved'])->assertForbidden();
        $this->assertSame(3, (int) $user->fresh()->rol_id);
    }

    public function test_inactive_account_cannot_access_protected_json_endpoint(): void
    {
        $user = $this->signIn(3);
        $user->estado_id = 2;
        $this->getJson('/historial-contenedores')->assertForbidden();
    }

    public function test_outdated_credential_session_is_revoked(): void
    {
        $user = $this->signIn(3);
        $this->withSession(['credential_version' => (int) $user->credential_version + 1])
            ->get('/historial-contenedores')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_setup_without_temporary_credential_grant_is_forbidden(): void
    {
        $this->postJson('/definir-contrasena', ['password' => 'AuditOnly123!', 'password_confirmation' => 'AuditOnly123!'])
            ->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_legacy_get_logout_cannot_end_an_authenticated_session(): void
    {
        $user = $this->signIn(1);
        $this->get('/logout.php')->assertNotFound();
        $this->assertAuthenticatedAs($user);
    }

    public function test_both_branch_change_routes_require_csrf_without_losing_authorization(): void
    {
        $user = $this->signIn(1);
        $this->app['env'] = 'local';
        $token = 'security-audit-csrf-token';
        $this->withSession(['_token' => $token]);
        foreach (['/sucursal-activa', '/cambiar_sucursal.php'] as $path) {
            $data = ['sucursal_id' => $user->sucursal_id];
            $this->post($path, $data)->assertStatus(419);
            $this->post($path, $data + ['_token' => $token])->assertRedirect();
            $this->assertAuthenticatedAs($user);
            $this->assertSame((int) $user->sucursal_id, (int) session('sucursal_activa'));
        }
        $this->signIn(3);
        $this->post('/cambiar_sucursal.php', ['sucursal_id' => $user->sucursal_id, '_token' => $token])->assertForbidden();
    }

    public function test_post_logout_requires_csrf_and_ends_the_session_only_with_a_valid_token(): void
    {
        $user = $this->signIn(3);
        $this->app['env'] = 'local';
        $this->withSession(['_token' => 'logout-audit-csrf-token']);
        $this->post('/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($user);
        $this->post('/logout', ['_token' => 'logout-audit-csrf-token'])->assertRedirect('/login');
        $this->assertGuest();
    }

    private function signIn(int $role): User
    {
        $user = $role === 1 ? User::where('rol_id', 1)->where('estado_id', 1)->firstOrFail()
            : User::where('email', match ($role) {
                2 => 'demo.admins.1@example.test', 3 => 'demo.operators.1@example.test',
                4 => 'demo.mechanics.1@example.test', 5 => 'demo.auditors.1@example.test',
            })->firstOrFail();
        $this->actingAs($user)->withSession(['sucursal_activa' => (int) $user->sucursal_id,
            'credential_version' => (int) $user->credential_version]);

        return $user;
    }
}
