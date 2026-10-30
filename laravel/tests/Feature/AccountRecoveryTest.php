<?php

namespace Tests\Feature;

use App\Jobs\RequestAccountRecovery;
use App\Jobs\SendAccountCredential;
use App\Livewire\Notifications\Inbox;
use App\Mail\AccountCredentialMail;
use App\Models\User;
use App\Services\AccountRecovery;
use App\Services\SystemNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;

class AccountRecoveryTest extends UserManagementInterfaceTest
{
    public function test_account_recovery_can_be_reviewed_in_the_inbox_modal_and_marks_only_its_notice_read(): void
    {
        $target = User::where('username', 'Local')->firstOrFail();
        $super = User::where('username', 'Super')->firstOrFail();
        app(AccountRecovery::class)->request($target->email, 'Necesito recuperar mi acceso de trabajo.');
        $notice = DB::table('system_notifications')->where('user_id', $super->id)->where('category', 'account-recovery')->first();
        $this->actingAs($super);
        Livewire::test(Inbox::class)
            ->assertViewHas('counts', fn ($counts) => $counts['account-recovery'] === 1)
            ->call('openRecovery', $notice->id)->assertSet('recoveryModalOpen', true)
            ->assertSee($target->email)->assertSee('Motivo de la solicitud')->assertSee('Aprobar solicitud')
            ->assertViewHas('counts', fn ($counts) => ($counts['account-recovery'] ?? 0) === 0)
            ->call('reviewRecovery', 'approved')->assertSet('recoveryModalOpen', false);
        $this->assertDatabaseHas('account_recovery_requests', ['status' => 'approved', 'reviewed_by' => $super->id]);
        $this->assertNotNull(DB::table('system_notifications')->where('id', $notice->id)->value('read_at'));
        Queue::assertPushed(SendAccountCredential::class, 1);
        $this->get(route('notifications.index', ['category' => 'account-recovery', 'recovery_notification' => $notice->id]))
            ->assertOk()->assertSee('Solicitud de acceso')->assertSee('Aprobada')->assertDontSee('Aprobar solicitud');
    }

    public function test_account_recovery_modal_forbids_other_roles_and_notifications_owned_by_other_users(): void
    {
        $target = User::where('username', 'Local')->firstOrFail();
        $super = User::where('username', 'Super')->firstOrFail();
        app(AccountRecovery::class)->request($target->email, 'Necesito recuperar mi acceso de trabajo.');
        $notice = DB::table('system_notifications')->where('user_id', $super->id)->where('category', 'account-recovery')->first();
        $this->actingAs(User::where('username', 'Admin')->firstOrFail());
        Livewire::test(Inbox::class)->call('openRecovery', $notice->id)->assertForbidden();
        DB::table('system_notifications')->where('id', $notice->id)->update(['user_id' => $target->id]);
        $this->actingAs($super);
        Livewire::test(Inbox::class)->call('openRecovery', $notice->id)->assertNotFound();
        Queue::assertNotPushed(SendAccountCredential::class);
    }

    public function test_account_notification_tab_counts_ignore_read_notices_and_do_not_depend_on_the_read_filter(): void
    {
        $super = User::where('username', 'Super')->firstOrFail();
        foreach ([['repairs', null], ['repairs', now()], ['audits', now()], ['audits', null]] as [$category, $readAt]) {
            DB::table('system_notifications')->insert([
                'user_id' => $super->id, 'branch_id' => 1, 'category' => $category, 'audience' => 'internal',
                'event_key' => 'test:'.uniqid(), 'title' => 'Aviso de prueba', 'body' => 'Contenido', 'created_at' => now(), 'read_at' => $readAt,
            ]);
        }
        $this->actingAs($super);
        Livewire::test(Inbox::class)
            ->assertViewHas('counts', fn ($counts) => $counts->sum() === 2 && $counts['repairs'] === 1 && $counts['audits'] === 1)
            ->set('status', 'read')
            ->assertViewHas('notifications', fn ($notifications) => $notifications->total() === 2)
            ->assertViewHas('counts', fn ($counts) => $counts->sum() === 2)
            ->call('readAll')->assertViewHas('counts', fn ($counts) => $counts->sum() === 0);
    }

    public function test_account_recovery_reason_accepts_150_characters_and_rejects_151(): void
    {
        Queue::fake([RequestAccountRecovery::class]);
        $this->get(route('login'))->assertOk()
            ->assertSee('maxlength="150"', false)
            ->assertDontSee('Tu solicitud será revisada por el superadmin.');
        $reason = 'Olvidé mi contraseña de acceso y necesito restablecerla para continuar con las tareas de mi puesto. ';
        $reason = mb_substr($reason.str_repeat('Detalles adicionales. ', 4), 0, 150);
        $this->postJson(route('account.recovery'), ['email' => 'local@example.test', 'reason' => $reason.'a'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        Queue::assertNotPushed(RequestAccountRecovery::class);
        $this->post(route('account.recovery'), ['email' => 'local@example.test', 'reason' => $reason])
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();
        Queue::assertPushed(RequestAccountRecovery::class, 1);
    }

    public function test_account_recovery_reason_rejects_short_text_and_repeated_filler(): void
    {
        Queue::fake([RequestAccountRecovery::class]);
        foreach ([str_repeat('a', 59), str_repeat('a', 60), str_repeat('1234567890', 6), str_repeat('abc', 20), str_repeat('clave ', 12)] as $reason) {
            $this->postJson(route('account.recovery'), ['email' => 'local@example.test', 'reason' => $reason])
                ->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        Queue::assertNotPushed(RequestAccountRecovery::class);
        $this->post(route('account.recovery'), ['email' => 'local@example.test', 'reason' => 'No recuerdo mi contraseña y necesito recuperar el acceso para trabajar.'])
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();
        Queue::assertPushed(RequestAccountRecovery::class, 1);
    }

    public function test_account_email_contains_branded_html_an_embedded_logo_and_a_plain_text_alternative(): void
    {
        config(['mail.mailers.array' => ['transport' => 'array'], 'mail.mailers.account_credentials.username' => 'sender@example.test']);
        $code = 'Test<Code>&123!';
        $sent = Mail::mailer('array')->to('recipient@example.test')->send(new AccountCredentialMail($code));
        $email = $sent->getSymfonySentMessage()->getOriginalMessage();
        $this->assertStringContainsString('PROPORTSA', $email->getHtmlBody());
        $this->assertStringContainsString('Tu clave temporal', $email->getHtmlBody());
        $this->assertStringContainsString(e($code), $email->getHtmlBody());
        $this->assertStringContainsString('cid:', $email->getHtmlBody());
        $this->assertStringNotContainsString('127.0.0.1', $email->getHtmlBody());
        $this->assertStringContainsString('Válida durante '.config('account-recovery.code_minutes').' minutos', $email->getHtmlBody());
        $this->assertStringContainsString('contraseña actual no ha cambiado', $email->getTextBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('inline', $email->getAttachments()[0]->getDisposition());
        $this->assertSame('image', $email->getAttachments()[0]->getMediaType());
    }

    public function test_account_recovery_is_a_filter_tab_with_counts_visible_only_to_superadmin(): void
    {
        $target = User::where('username', 'Local')->firstOrFail();
        $super = User::where('username', 'Super')->firstOrFail();
        app(AccountRecovery::class)->request($target->email, 'Necesito recuperar mi acceso de trabajo.');
        DB::table('system_notifications')->insert([
            'user_id' => $super->id, 'branch_id' => 1, 'category' => 'repairs', 'audience' => 'internal',
            'event_key' => 'test-repair', 'title' => 'Aviso de reparacion independiente', 'body' => 'Otra categoria.', 'created_at' => now(),
        ]);
        $this->actingAs($super);
        Livewire::test(Inbox::class)
            ->assertSee('Recuperación de contraseñas')
            ->assertSee('Aviso de reparacion independiente')
            ->assertDontSee('class="button secondary" href="'.route('notifications.recovery.index').'"', false)
            ->set('category', 'account-recovery')
            ->assertViewHas('counts', fn ($counts) => $counts['account-recovery'] === 1)
            ->assertViewHas('notifications', fn ($notifications) => $notifications->total() === 1)
            ->assertSee('Solicitud de recuperación de contraseña')
            ->assertDontSee('Aviso de reparacion independiente');
        $this->actingAs(User::where('username', 'Admin')->firstOrFail());
        Livewire::test(Inbox::class)->assertDontSee('Recuperación de contraseñas');
        Queue::assertNotPushed(SendAccountCredential::class);
    }

    public function test_account_public_request_has_the_same_response_for_existing_unknown_and_disabled_accounts(): void
    {
        Queue::fake([RequestAccountRecovery::class, SendAccountCredential::class]);
        $messages = [];
        foreach (['local@example.test', 'unknown@example.test', 'inactivo@example.test'] as $email) {
            $this->post(route('account.recovery'), ['email' => $email, 'reason' => 'Olvidé mi contraseña y necesito recuperar el acceso para continuar trabajando.'])
                ->assertRedirect(route('login'))->assertSessionHas('status');
            $messages[] = session('status');
        }
        $this->assertCount(1, array_unique($messages));
        Queue::assertPushed(RequestAccountRecovery::class, 3);
        Queue::assertPushed(RequestAccountRecovery::class, fn ($job) => $job->queue === 'account-recovery' && $job->connection === 'database');
        Queue::assertNotPushed(SendAccountCredential::class);
        $this->assertDatabaseCount('account_recovery_requests', 0);
        $this->postJson(route('account.recovery'), ['email' => 'bad', 'reason' => 'corto'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'reason']);
        $this->postJson(route('account.recovery'), ['email' => ['invalid'], 'reason' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'reason']);
    }

    public function test_account_requests_wait_for_approval_and_archiving_does_not_resolve_them(): void
    {
        $this->notificationTables();
        $target = User::where('username', 'Local')->firstOrFail();
        $super = User::where('username', 'Super')->firstOrFail();
        $password = $target->password;
        $recovery = app(AccountRecovery::class);
        $recovery->request($target->email, 'Olvide la contrasena de mi cuenta de trabajo.');
        $recovery->request($target->email, 'Otra solicitud para la misma cuenta de trabajo.');
        $this->assertDatabaseCount('account_recovery_requests', 1);
        $this->assertSame($password, $target->fresh()->password);
        Queue::assertNotPushed(SendAccountCredential::class);
        $notice = DB::table('system_notifications')->where('category', 'account-recovery')->first();
        $this->assertNotNull($notice);
        $this->assertSame($super->id, $notice->user_id);
        $notifications = app(SystemNotifications::class);
        $this->assertSame(1, $notifications->inbox($super, 2)->where('n.category', 'account-recovery')->count());
        $notifications->act($super, 2, 'archive', [$notice->id], 'inbox');
        $this->assertDatabaseHas('account_recovery_requests', ['status' => 'pending']);
        $this->actingAs($super)->withSession(['sucursal_activa' => 2]);
        $this->post(route('notifications.open', $notice->id), ['folder' => 'archived'])
            ->assertRedirect(route('notifications.index', ['category' => 'account-recovery', 'folder' => 'archived', 'recovery_notification' => $notice->id]));
        $this->get(route('notifications.recovery.index'))->assertRedirect(route('notifications.index', ['category' => 'account-recovery']));
        Livewire::test(Inbox::class)->set('folder', 'archived')->call('openRecovery', $notice->id)
            ->assertSet('recoveryModalOpen', true)->assertSee($target->email);
        $id = DB::table('account_recovery_requests')->value('id');
        $this->post(route('notifications.recovery.review', $id), ['decision' => 'approved'])->assertRedirect();
        $this->assertDatabaseHas('account_recovery_requests', ['id' => $id, 'status' => 'approved', 'reviewed_by' => $super->id]);
        Queue::assertPushed(SendAccountCredential::class, 1);
        $this->post(route('notifications.recovery.review', $id), ['decision' => 'approved'])->assertConflict();
        Queue::assertPushed(SendAccountCredential::class, 1);
        $this->assertSame($password, $target->fresh()->password);
    }

    public function test_account_rejection_leaves_the_password_and_sends_no_credential(): void
    {
        $this->notificationTables();
        $target = User::where('username', 'Local')->firstOrFail();
        $hash = $target->password;
        app(AccountRecovery::class)->request($target->email, 'Necesito recuperar mi acceso de trabajo.');
        $id = DB::table('account_recovery_requests')->value('id');
        $this->actingAs(User::where('username', 'Super')->firstOrFail())->withSession(['sucursal_activa' => 1]);
        $this->post(route('notifications.recovery.review', $id), ['decision' => 'rejected'])->assertRedirect();
        $this->assertDatabaseHas('account_recovery_requests', ['id' => $id, 'status' => 'rejected']);
        $this->assertSame($hash, $target->fresh()->password);
        Queue::assertNotPushed(SendAccountCredential::class);
    }

    public function test_account_superadmin_recovers_by_email_without_approval_and_disabled_accounts_do_not(): void
    {
        $super = User::where('username', 'Super')->firstOrFail();
        (new RequestAccountRecovery($super->email, 'Olvide mi contrasena del sistema.'))->handle(app(AccountRecovery::class));
        (new RequestAccountRecovery('inactivo@example.test', 'Olvide mi contrasena del sistema.'))->handle(app(AccountRecovery::class));
        Queue::assertPushed(SendAccountCredential::class, 1);
        Queue::assertPushed(SendAccountCredential::class, fn ($job) => $job->userId === $super->id && $job->queue === 'account-recovery' && $job->connection === 'database');
        $this->assertDatabaseCount('account_recovery_requests', 0);
    }

    public function test_account_temporary_login_is_guest_only_single_use_and_enforces_password_policy(): void
    {
        $this->freezeTime();
        $target = User::where('username', 'Local')->firstOrFail();
        $this->seedCode($target);
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'Temporary-Code-1234567890'])
            ->assertRedirect(route('account.password'));
        $this->assertGuest();
        $this->get('/usuarios')->assertRedirect(route('login'));
        $this->get(route('account.password'))->assertOk()->assertSee('Confirmar contraseña')
            ->assertSee('data-account-password-rule="length"', false)
            ->assertSee('data-account-password-rule="uppercase"', false)
            ->assertSee('data-account-password-rule="number"', false)
            ->assertSee('data-account-password-rule="symbol"', false)
            ->assertDontSee('Volver al acceso');
        $this->post(route('account.password.store'), ['password' => 'weak', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertFalse(Hash::check('weak', $target->fresh()->password));
        $this->post(route('account.password.store'), ['password' => 'ChangedSecret123!', 'password_confirmation' => 'ChangedSecret123!'])
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(Hash::check('ChangedSecret123!', $target->fresh()->password));
        $this->assertSame(2, $target->fresh()->credential_version);
        $this->assertDatabaseCount('account_credential_challenges', 0);
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'Temporary-Code-1234567890'])->assertSessionHasErrors('email');
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'ChangedSecret123!'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($target);
    }

    public function test_account_expired_codes_and_disabled_or_changed_accounts_cannot_claim(): void
    {
        $this->freezeTime();
        $target = User::where('username', 'Local')->firstOrFail();
        $this->seedCode($target);
        $this->travel(16)->minutes();
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'Temporary-Code-1234567890'])->assertSessionHasErrors('email');
        $this->seedCode($target);
        $target->update(['estado_id' => 2]);
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'Temporary-Code-1234567890'])->assertSessionHasErrors('email');
        $target->update(['estado_id' => 1, 'email' => 'changed@example.test']);
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'Temporary-Code-1234567890'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_account_grant_expires_and_cannot_be_used_in_another_session(): void
    {
        $this->freezeTime();
        $target = User::where('username', 'Local')->firstOrFail();
        $this->seedCode($target);
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'Temporary-Code-1234567890'])->assertRedirect(route('account.password'));
        $grant = session('credential_grant');
        $this->flushSession();
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'Temporary-Code-1234567890'])->assertSessionHasErrors('email');
        $this->withSession(['credential_grant' => $grant]);
        $this->travel(11)->minutes();
        $this->post(route('account.password.store'), ['password' => 'ChangedSecret123!', 'password_confirmation' => 'ChangedSecret123!'])->assertForbidden();
        $this->assertTrue(Hash::check('Secret123!', $target->fresh()->password));
    }

    public function test_account_delivery_hashes_the_code_invalidates_old_codes_and_ignores_stale_jobs(): void
    {
        Mail::fake();
        config(['mail.mailers.account_credentials.username' => 'sender@example.test']);
        $target = User::where('username', 'Local')->firstOrFail();
        $this->seedCode($target);
        $recovery = app(AccountRecovery::class);
        $recovery->queueCredential($target, 'recovery');
        $oldKey = DB::table('account_credential_challenges')->value('delivery_key');
        $recovery->queueCredential($target, 'recovery');
        $key = DB::table('account_credential_challenges')->value('delivery_key');
        (new SendAccountCredential($target->id, $recovery->fingerprint($target), 'recovery', $oldKey))->handle($recovery);
        Mail::assertNothingSent();
        (new SendAccountCredential($target->id, $recovery->fingerprint($target), 'recovery', $key))->handle($recovery);
        $stored = DB::table('account_credential_challenges')->first();
        Mail::assertSent(AccountCredentialMail::class, function ($mail) use ($target, $stored): bool {
            $code = $mail->content()->with['temporaryCode'];
            $this->assertNotSame($code, $stored->code_hash);
            $this->assertTrue(Hash::check($code, $stored->code_hash));
            $this->assertStringNotContainsString($code, json_encode(DB::table('account_security_events')->get()));

            return $mail->hasTo($target->email);
        });
        $this->assertSame($target->password, $target->fresh()->password);
        Queue::assertPushed(SendAccountCredential::class, 2);
    }

    public function test_account_credentials_never_use_log_mail_transport(): void
    {
        Mail::fake();
        config(['mail.mailers.account_credentials.transport' => 'log']);
        $target = User::where('username', 'Local')->firstOrFail();
        $this->seedCode($target);
        try {
            (new SendAccountCredential($target->id, app(AccountRecovery::class)->fingerprint($target), 'recovery', 'test'))->handle(app(AccountRecovery::class));
            $this->fail('A log transport must not receive a credential.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SMTP', $exception->getMessage());
        }
        Mail::assertNothingSent();
    }

    public function test_account_requests_and_reset_actions_are_superadmin_only(): void
    {
        $target = User::where('username', 'Local')->firstOrFail();
        foreach (['Admin', 'Local', 'Otro'] as $name) {
            $this->actingAs(User::where('username', $name)->firstOrFail())->withSession(['sucursal_activa' => $name === 'Otro' ? 2 : 1]);
            $this->get(route('notifications.recovery.index'))->assertForbidden();
            $this->post(route('notifications.recovery.review', 1), ['decision' => 'approved'])->assertForbidden();
            $this->post(route('users.send-credential', $target))->assertForbidden();
        }
        $super = User::where('username', 'Super')->firstOrFail();
        $this->actingAs($super)->post(route('users.send-credential', $super))->assertForbidden();
        Queue::assertNotPushed(SendAccountCredential::class);
    }

    public function test_account_old_sessions_are_revoked_after_a_credential_version_change(): void
    {
        $target = User::where('username', 'Local')->firstOrFail();
        $this->actingAs($target)->withSession(['sucursal_activa' => 1, 'credential_version' => 1]);
        $target->forceFill(['credential_version' => 2])->save();
        $this->get('/usuarios')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_account_login_and_public_recovery_are_rate_limited(): void
    {
        Queue::fake([RequestAccountRecovery::class]);
        $key = 'login:'.hash('sha256', 'local@example.test');
        RateLimiter::increment($key, 60, 10);
        $this->post(route('login.store'), ['email' => 'local@example.test', 'password' => 'Secret123!'])->assertSessionHasErrors('email');
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('account.recovery'), ['email' => 'local@example.test', 'reason' => 'Necesito recuperar mi cuenta de trabajo porque no recuerdo mi contraseña.'])->assertRedirect(route('login'));
        }
        Queue::assertPushed(RequestAccountRecovery::class, 3);
    }

    public function test_account_superadmin_email_is_protected_from_user_management(): void
    {
        $super = User::where('username', 'Super')->firstOrFail();
        $this->actingAs($super)->patchJson(route('users.update', $super), [
            'username' => $super->username, 'email' => 'attacker@example.test', 'rol_id' => 1, 'sucursal_id' => 1,
        ])->assertForbidden();
        $this->assertSame('super@example.test', $super->fresh()->email);
    }

    public function test_account_email_changes_invalidate_existing_codes_and_require_activation_of_the_new_email(): void
    {
        $target = User::where('username', 'Local')->firstOrFail();
        $this->seedCode($target);
        $this->actingAs(User::where('username', 'Admin')->firstOrFail());
        $this->patchJson(route('users.update', $target), ['username' => 'Local', 'email' => 'new@example.test', 'rol_id' => 3, 'sucursal_id' => 1])->assertOk();
        $target->refresh();
        $this->assertTrue($target->requires_password_setup);
        $this->assertSame(2, $target->credential_version);
        $this->assertDatabaseHas('account_credential_challenges', ['user_id' => $target->id, 'code_hash' => null]);
        Queue::assertPushed(SendAccountCredential::class, fn ($job) => $job->userId === $target->id && $job->purpose === 'activation');
    }

    public function test_account_pending_approval_cannot_be_applied_after_the_account_changes(): void
    {
        $this->notificationTables();
        $target = User::where('username', 'Local')->firstOrFail();
        app(AccountRecovery::class)->request($target->email, 'Necesito recuperar mi acceso de trabajo.');
        $id = DB::table('account_recovery_requests')->value('id');
        $target->update(['estado_id' => 2]);
        $this->actingAs(User::where('username', 'Super')->firstOrFail());
        $this->post(route('notifications.recovery.review', $id), ['decision' => 'approved'])->assertConflict();
        Queue::assertNotPushed(SendAccountCredential::class);
    }

    public function test_account_request_reasons_are_escaped_in_the_superadmin_view(): void
    {
        $target = User::where('username', 'Local')->firstOrFail();
        app(AccountRecovery::class)->request($target->email, '<script>alert("recovery")</script> olvide la clave.');
        $this->actingAs(User::where('username', 'Super')->firstOrFail());
        $notice = DB::table('system_notifications')->where('category', 'account-recovery')->first();
        Livewire::test(Inbox::class)->call('openRecovery', $notice->id)
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("recovery")</script>', false);
    }

    public function test_account_password_setup_rejects_missing_uppercase_numbers_symbols_and_excess_length(): void
    {
        $target = User::where('username', 'Local')->firstOrFail();
        $this->seedCode($target);
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'Temporary-Code-1234567890'])->assertRedirect(route('account.password'));
        foreach (['lowercase123!', 'UppercaseOnly!', 'MissingSymbol123', str_repeat('A', 65).'1!', 'Secret123!'] as $password) {
            $this->post(route('account.password.store'), ['password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
            $this->assertTrue(Hash::check('Secret123!', $target->fresh()->password));
        }
    }

    public function test_account_smtp_failure_does_not_store_or_log_the_credential_in_the_exception(): void
    {
        $target = User::where('username', 'Local')->firstOrFail();
        $recovery = app(AccountRecovery::class);
        $recovery->queueCredential($target, 'recovery');
        $key = DB::table('account_credential_challenges')->value('delivery_key');
        $sender = \Mockery::mock();
        Mail::shouldReceive('mailer')->once()->with('account_credentials')->andReturn($sender);
        $sender->shouldReceive('to')->once()->with($target->email)->andReturnSelf();
        $sender->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP diagnostics containing secret content'));
        try {
            (new SendAccountCredential($target->id, $recovery->fingerprint($target), 'recovery', $key))->handle($recovery);
            $this->fail('SMTP failure must be reported safely.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('secret content', $exception->getMessage());
        }
        $this->assertDatabaseHas('account_credential_challenges', ['user_id' => $target->id, 'code_hash' => null]);
        $this->assertDatabaseMissing('account_security_events', ['action' => 'credential_sent']);
        Queue::assertPushed(SendAccountCredential::class, 1);
    }

    private function seedCode(User $target): void
    {
        DB::table('account_credential_challenges')->updateOrInsert(['user_id' => $target->id], [
            'delivery_key' => 'test', 'fingerprint' => app(AccountRecovery::class)->fingerprint($target),
            'code_hash' => Hash::make('Temporary-Code-1234567890'), 'grant_hash' => null, 'grant_expires_at' => null,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    private function notificationTables(): void
    {
        if (Schema::hasTable('system_notifications')) {
            return;
        }
        foreach (['2026_10_20_170939_create_system_notifications_table.php', '2026_10_20_172530_add_personal_folders_to_system_notifications.php',
            '2026_10_20_175848_add_expiry_to_system_notifications.php', '2026_10_21_052602_add_activity_details_to_system_notifications.php',
            '2026_10_21_060157_add_bell_dismissed_at_to_system_notifications.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }
}
