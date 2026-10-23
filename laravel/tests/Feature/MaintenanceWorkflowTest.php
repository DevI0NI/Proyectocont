<?php

namespace Tests\Feature;

use App\Livewire\Containers\InventoryTable;
use App\Livewire\ExternalAudits\Overview;
use App\Livewire\Notifications\Inbox;
use App\Livewire\Shell\NotificationBell;
use App\Models\Bloque;
use App\Models\Contenedor;
use App\Models\User;
use App\Services\AccessPermissions;
use App\Services\ContainerAuditAssignment;
use App\Services\IngresoService;
use App\Services\MaintenanceExchangeRate;
use App\Services\MaintenanceWorkflow;
use App\Services\PypAuditService;
use App\Services\SystemNotifications;
use Carbon\Carbon;
use Database\Seeders\MaintenanceCatalogSeeder;
use Database\Seeders\MaintenanceReferencePriceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MaintenanceWorkflowTest extends TestCase
{
    private User $mechanic;

    private User $other;

    private User $admin;

    private User $auditor;

    private int $branch;

    private int $container;

    private int $cycle;

    private int $part;

    private int $service;

    private int $oosStatus;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::setDefaultDriver('array');
        Http::fake([MaintenanceExchangeRate::SOURCE => Http::response('<root><VarDolar><fecha>'.now('America/Guatemala')->format('d/m/Y').'</fecha><referencia>7.65000</referencia></VarDolar></root>')]);
        config(['database.default' => 'mysql', 'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '3306', 'database.connections.mysql.database' => 'proyectocont_v2.1',
            'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '']);
        DB::purge('mysql');
        DB::beginTransaction();
        Storage::fake('local');
        $this->branch = DB::table('sucursales')->insertGetId(['nombre' => 'QA Fase 6 '.uniqid(), 'ubicacion' => 'Temporal', 'estado_id' => 1]);
        $yard = DB::table('patios')->insertGetId(['nombre' => 'Patio mecanico', 'sucursal_id' => $this->branch, 'estado_id' => 1]);
        $block = DB::table('bloques')->insertGetId(['numero' => 106, 'patio_id' => $yard, 'capacidad_maxima' => 25, 'capacidad_actual' => 1, 'estado_id' => 1]);
        foreach (['mechanic' => 4, 'other' => 4, 'admin' => 2, 'auditor' => 5] as $key => $role) {
            $this->{$key} = User::create(['username' => 'QA_'.$key.'_'.uniqid(), 'email' => uniqid().'@example.test',
                'password' => Hash::make('Secret123!'), 'rol_id' => $role, 'sucursal_id' => $this->branch, 'estado_id' => 1]);
        }
        $line = DB::table('shipping_lines')->insertGetId(['name' => 'Naviera mantenimiento', 'name_key' => uniqid(), 'status' => 1]);
        DB::table('shipping_line_branch')->insert(['shipping_line_id' => $line, 'branch_id' => $this->branch]);
        $company = DB::table('external_audit_companies')->insertGetId(['name' => 'Auditora mantenimiento', 'name_key' => uniqid(), 'status' => 1]);
        DB::table('shipping_line_branch_auditor')->insert(['shipping_line_id' => $line, 'branch_id' => $this->branch, 'auditor_id' => $company]);
        $this->auditor->update(['external_audit_company_id' => $company, 'external_access_level' => 'inspector']);
        $this->auditor->authorizedExternalBranches()->sync([$this->branch]);
        $type = DB::table('tipos_unidad')->where('estado_id', 1)->value('id');
        $this->oosStatus = (int) app(MaintenanceWorkflow::class)->outOfServiceStatuses()->value('id');
        $this->assertGreaterThan(0, $this->oosStatus);
        $this->container = DB::table('contenedores')->insertGetId(['nombre' => 'MNTU'.random_int(1000000, 9999999),
            'tipo_unidad_id' => $type, 'modelo_id' => DB::table('modelos')->where('tipo_unidad_id', $type)->value('id'),
            'estatus_unidad_id' => DB::table('estatus_contenedores')->insertGetId(['nombre' => 'DMG QA', 'tipo_estatus_id' => 1, 'estado_id' => 1]),
            'estatus_box_id' => DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 2, 'estado_id' => 1]),
            'estatus_ipsa_id' => $this->oosStatus, 'bloque_id' => $block, 'posicion' => 1, 'fecha_ingreso' => now(), 'estado_id' => 1]);
        $this->cycle = DB::table('container_ingress_cycles')->insertGetId(['container_id' => $this->container,
            'branch_id' => $this->branch, 'shipping_line_id' => $line, 'shipping_line_name' => 'Naviera mantenimiento',
            'audit_company_id' => $company, 'audit_company_name' => 'Auditora mantenimiento', 'entered_at' => now(),
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('contenedores')->where('id', $this->container)->update(['current_ingress_cycle_id' => $this->cycle]);
        DB::table('pyp_auditoria')->insert(['contenedor_id' => $this->container, 'ingress_cycle_id' => $this->cycle,
            'external_audit_company_id' => $company, 'estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'Aprobacion anterior',
            'fecha_registro' => now(), 'usuario_id' => $this->auditor->id, 'estado_id' => 1]);
        $this->part = DB::table('repair_parts')->insertGetId(['code' => 'QA-'.uniqid(), 'name' => 'Sellador industrial', 'unit' => 'tubo', 'status' => 1]);
        $this->service = DB::table('repair_services')->insertGetId(['code' => 'QA-'.uniqid(), 'name' => 'Soldadura y sellado de panel', 'unit' => 'Servicio', 'status' => 1]);
        $this->actingAs($this->mechanic)->withSession(['sucursal_activa' => $this->branch]);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_container_repair_history_is_paginated_and_keeps_other_mechanics_jobs_private(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'completed']);
        $copy = (array) DB::table('maintenance_jobs')->find($id);
        unset($copy['id']);
        for ($index = 0; $index < 15; $index++) {
            DB::table('maintenance_jobs')->insert($copy);
        }
        $copy['mechanic_id'] = $this->other->id;
        $copy['repair_type'] = 'Reparacion privada de otro mecanico';
        $privateId = DB::table('maintenance_jobs')->insertGetId($copy);
        $url = route('corrections.history', ['job' => $id, 'return_to' => '/mantenimiento/ordenes']);
        $this->get($url)->assertOk()->assertSee('Reparaciones')
            ->assertDontSee('Reparacion privada de otro mecanico')
            ->assertViewHas('filterOptions', fn (array $options): bool => ! isset($options['mechanic'][$this->other->id])
                && ! isset($options['name']['Reparacion privada de otro mecanico']))
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 16 && $jobs->count() === 10 && $jobs->lastPage() === 2)
            ->assertViewHas('returnUrl', '/mantenimiento/ordenes');
        $this->get($url.'&page=2')->assertOk()
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->count() === 6);
        $this->get(route('corrections.history', $privateId))->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertViewHas('filterOptions', fn (array $options): bool => isset($options['mechanic'][$this->mechanic->id], $options['mechanic'][$this->other->id]))
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 17);
    }

    public function test_repair_history_search_column_filters_sorting_and_page_size_share_the_same_scope(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update([
            'status' => 'completed', 'repair_type' => 'Soldadura especial', 'created_at' => '2026-10-22 03:30:00',
        ]);
        $copy = (array) DB::table('maintenance_jobs')->find($id);
        unset($copy['id']);
        $copy['repair_type'] = 'Cambio de empaque';
        $copy['created_at'] = '2026-10-23 03:30:00';
        $second = DB::table('maintenance_jobs')->insertGetId($copy);
        $this->get(route('corrections.history', ['job' => $id, 'q' => 'especial']))->assertOk()
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 1 && (int) $jobs->first()->id === $id);
        $this->get(route('corrections.history', ['job' => $id, 'q' => '21/10/2026']))->assertOk()
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 1 && (int) $jobs->first()->id === $id);
        $this->get(route('corrections.history', ['job' => $id, 'filters' => ['name' => 'Cambio de empaque', 'status' => 'completed', 'mechanic' => (string) $this->mechanic->id]]))->assertOk()
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 1 && (int) $jobs->first()->id === $second);
        $this->get(route('corrections.history', ['job' => $id, 'filters' => ['name' => 'Cambio de empaque']]))->assertOk()
            ->assertViewHas('filterOptions', fn (array $options): bool => array_keys($options['name']) === ['Cambio de empaque', 'Soldadura especial']
                && $options['mechanic'] === [$this->mechanic->id => $this->mechanic->username]
                && $options['status'] === MaintenanceWorkflow::STATES);
        $this->get(route('corrections.history', ['job' => $id, 'filters' => ['mechanic' => (string) $this->other->id]]))->assertOk()
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 0);
        $this->get(route('corrections.history', ['job' => $id, 'sort' => 'date', 'order' => 'desc', 'per_page' => 5]))->assertOk()
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->perPage() === 5 && (int) $jobs->first()->id === $second);
        $this->get(route('corrections.history', ['job' => $id, 'sort' => 'invalid', 'per_page' => 999]))->assertOk()
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->perPage() === 10 && $jobs->total() === 2);
    }

    public function test_external_repair_history_only_includes_the_assigned_ingress_and_submitted_work(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'completed']);
        $copy = (array) DB::table('maintenance_jobs')->find($id);
        unset($copy['id']);
        $copy['ingress_cycle_id'] = null;
        $copy['repair_type'] = 'Reparacion de otro ingreso';
        DB::table('maintenance_jobs')->insert($copy);
        $copy['ingress_cycle_id'] = $this->cycle;
        $copy['status'] = 'in_progress';
        DB::table('maintenance_jobs')->insert($copy);
        $this->actingAs($this->auditor)->get(route('corrections.history', $id))->assertOk()
            ->assertDontSee('Reparacion de otro ingreso')
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 1);
        $this->withSession(['sucursal_activa' => 0])->get(route('corrections.history', $id))->assertForbidden();
    }

    public function test_repair_history_detail_reuses_the_three_steps_without_editing_or_review_actions(): void
    {
        $id = $this->start();
        $return = '/reparaciones/'.$id.'/historial?page=2&filters%5Bname%5D=Soldadura';
        $url = route('corrections.detail', ['job' => $id, 'return_to' => $return]);
        $this->get($url)->assertOk()->assertViewIs('notifications.repair')->assertViewHas('readOnlyDetail', true)
            ->assertViewHas('returnUrl', $return)->assertSee('Detalle de reparación')->assertDontSee('@else', false)->assertSee('Evaluación inicial')
            ->assertDontSee('aria-controls="repairInterventionsMenu"', false)->assertDontSee('class="repair-interventions"', false)
            ->assertSee('Reparación y materiales')->assertSee('Resultado de la reparación')
            ->assertDontSee('Intervenciones')->assertDontSee('Historial de correcciones')->assertSee('data-notification-review', false)
            ->assertDontSee('data-maintenance-form', false)->assertDontSee('Confirmar trabajo realizado')
            ->assertDontSee('Actualizar estados del contenedor')->assertDontSee('Guardar cambios');
        $this->actingAs($this->other)->get($url)->assertForbidden();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'pending_review']);
        $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSee('Confirmar trabajo realizado');
        $this->actingAs($this->auditor)->get($url)->assertOk()->assertViewHas('readOnlyDetail', true);
    }

    public function test_internal_container_readers_can_consult_repair_history_without_maintenance_permissions(): void
    {
        $reader = User::create(['username' => 'QA_reader_'.uniqid(), 'email' => uniqid().'@example.test',
            'password' => Hash::make('Secret123!'), 'rol_id' => 3, 'sucursal_id' => $this->branch,
            'estado_id' => 1, 'permissions' => ['containers.view']]);
        $url = route('corrections.container-history', ['container' => $this->container]);
        $this->actingAs($reader)->get($url)->assertOk()->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 0);
        $this->actingAs($this->mechanic);
        $id = $this->start();
        foreach ([1, 2, 3] as $role) {
            $reader->update(['rol_id' => $role]);
            $this->actingAs($reader->fresh())->get($url)->assertOk()->assertViewHas('jobs', fn ($jobs): bool => $jobs->total() === 1);
            $this->get(route('corrections.detail', $id))->assertOk()->assertViewHas('readOnlyDetail', true)
                ->assertDontSee('Confirmar trabajo realizado')->assertDontSee('Actualizar estados del contenedor');
        }
        $reader->update(['rol_id' => 3]);
        $this->actingAs($reader->fresh())->post(route('notifications.confirm', $id), ['version' => 1])->assertForbidden();
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        Livewire::test(InventoryTable::class)->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertSee('Reparaciones')->call('setViewMode', 'yard3d')
            ->assertViewHas('yardScene', function (array $scene): bool {
                $item = collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers'])->firstWhere('id', $this->container);

                return str_contains($item['actions']['history'], '/contenedores/'.$this->container.'/reparaciones');
            });
        $reader->update(['permissions' => []]);
        $this->actingAs($reader->fresh())->get($url)->assertForbidden();
        $this->get(route('corrections.detail', $id))->assertForbidden();
        $this->get(route('corrections.original-photo', ['job' => $id, 'photo' => 1]))->assertForbidden();
        $reader->update(['permissions' => ['containers.view']]);
        $this->actingAs($reader->fresh())->withSession(['sucursal_activa' => 0])->get($url)->assertForbidden();
    }

    public function test_readonly_interventions_keep_the_original_snapshot_and_hide_unsubmitted_corrections(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['diagnosis' => 'Diagnostico original', 'work_performed' => 'Trabajo original', 'status' => 'completed']);
        $round = DB::table('maintenance_corrections')->insertGetId(['job_id' => $id, 'number' => 1, 'requested_by' => $this->auditor->id,
            'reason' => 'Sellar una fuga pendiente', 'work_performed' => 'Se corrigio la fuga', 'status' => 'submitted',
            'materials' => '[]', 'photos' => '[]', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $draft = DB::table('maintenance_corrections')->insertGetId(['job_id' => $id, 'number' => 2, 'requested_by' => $this->auditor->id,
            'reason' => 'Observacion sin entregar', 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $url = route('corrections.detail', ['job' => $id, 'intervention' => $round]);
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Intervenciones')
            ->assertSee('Solicitud de corrección')->assertSee('Se corrigio la fuga')->assertDontSee('Trabajo original')
            ->assertDontSee('Historial de correcciones')->assertDontSee('data-correction-form', false)
            ->assertViewHas('interventions', fn ($rounds): bool => $rounds->count() === 1)
            ->assertViewHas('materials', fn ($materials): bool => $materials->isEmpty());
        $this->get(route('corrections.detail', $id))->assertOk()->assertSee('Trabajo original')->assertSee('Diagnostico original');
        $this->get(route('corrections.detail', ['job' => $id, 'intervention' => $draft]))->assertNotFound();
        $this->get(route('corrections.detail', ['job' => $id, 'intervention' => 99999999]))->assertNotFound();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'diagnosis' => 'Diagnostico original', 'work_performed' => 'Trabajo original']);
    }

    public function test_external_supervisor_dashboard_is_not_available_to_inspectors_or_internal_accounts(): void
    {
        $this->actingAs($this->auditor)->get(route('external-audits.dashboard'))->assertForbidden();
        Livewire::test(Overview::class)->assertForbidden();
        $this->auditor->update(['external_access_level' => 'supervisor']);
        $this->actingAs($this->auditor->fresh())->get('/')->assertRedirect(route('external-audits.dashboard'));
        $this->get(route('external-audits.dashboard'))->assertOk()->assertSee('Auditora mantenimiento')->assertSee('Actividad por sucursal');
        Livewire::test(Overview::class)->assertViewHas('totals', fn ($totals): bool => $totals['total'] === 1);
        $this->actingAs($this->admin)->get(route('external-audits.dashboard'))->assertForbidden();
        Livewire::test(Overview::class)->assertForbidden();
        $this->auditor->update(['external_access_level' => 'inspector']);
        $this->actingAs($this->auditor->fresh())->get('/')->assertRedirect(route('pyp-audits.index'));
    }

    public function test_external_inventory_is_the_same_scoped_view_for_both_responsibilities(): void
    {
        foreach (['inspector', 'supervisor'] as $level) {
            $this->auditor->update(['external_access_level' => $level]);
            $this->actingAs($this->auditor->fresh())->get(route('pyp-audits.index'))->assertOk()->assertSee('Auditoría externa')
                ->assertSee('Auditora mantenimiento')->assertDontSee('Seguimiento PYP');
            Livewire::test(InventoryTable::class, ['pypMode' => false])
                ->assertSet('pypMode', true)->set('search', DB::table('contenedores')->where('id', $this->container)->value('nombre'))
                ->assertViewHas('pagination', fn ($pagination): bool => $pagination['total'] === 1);
        }
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(InventoryTable::class, ['pypMode' => true])->set('pypMode', false);
    }

    public function test_external_3d_keeps_foreign_equipment_anonymous_and_only_links_assigned_audits(): void
    {
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        $foreign = (array) $record;
        unset($foreign['id']);
        $foreign['nombre'] = 'PRIV'.random_int(1000000, 9999999);
        $foreign['posicion'] = 2;
        $foreign['current_ingress_cycle_id'] = null;
        $foreignId = DB::table('contenedores')->insertGetId($foreign);

        foreach (['inspector', 'supervisor'] as $level) {
            $this->auditor->update(['external_access_level' => $level]);
            $this->actingAs($this->auditor->fresh());
            $table = Livewire::test(InventoryTable::class)->call('selectPatio', $block->patio_id)
                ->call('selectBlock', $block->id)->call('setViewMode', 'yard3d')
                ->assertSet('viewMode', 'yard3d')->assertSee('Ver auditoría')->assertDontSee($foreign['nombre'])
                ->assertSee('contenedores asignados')->assertDontSee('% ocupado')
                ->assertViewHas('capacityCurrent', 1)
                ->assertViewHas('patios', fn ($patios): bool => (int) $patios->firstWhere('id', $block->patio_id)->assigned_count === 1)
                ->assertViewHas('blocks', fn ($blocks): bool => (int) $blocks->firstWhere('id', $block->id)->contenedores_activos_count === 1)
                ->assertViewHas('yardScene', function (array $scene) use ($record, $foreignId): bool {
                    $items = collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers']);
                    $own = $items->firstWhere('id', $this->container);
                    $anonymous = $items->firstWhere('anonymous', true);

                    return $scene['fit_to_viewport'] && $items->count() === 2
                        && $scene['blocks'][0]['ocupados'] === 1
                        && $own['nombre'] === $record->nombre
                        && $own['actions'] === ['info' => $own['url']]
                        && str_contains($own['url'], '/pyp?')
                        && $anonymous === ['anonymous' => true, 'posicion' => 2, 'matches' => false]
                        && $items->firstWhere('id', $foreignId) === null;
                });
            $table->set('search', 'NO-MATCH')->assertViewHas('capacityCurrent', 1)->assertViewHas('yardScene', function (array $scene): bool {
                return collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers'])
                    ->every(fn (array $item): bool => ! $item['matches']);
            });
            $table->set('search', '')->call('setViewMode', 'table')
                ->assertViewHas('pagination', fn (array $pagination): bool => $pagination['total'] === 1);
        }
    }

    public function test_supervisor_summary_does_not_count_another_company_or_an_unauthorized_branch(): void
    {
        $this->auditor->update(['external_access_level' => 'supervisor']);
        $this->actingAs($this->auditor->fresh());
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['external_audit_company_id' => null]);
        Livewire::test(Overview::class)->assertViewHas('totals', fn ($totals): bool => $totals['total'] === 0);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['external_audit_company_id' => $this->auditor->external_audit_company_id]);
        $branch = DB::table('sucursales')->insertGetId(['nombre' => 'Sucursal privada '.uniqid(), 'ubicacion' => 'Temporal', 'estado_id' => 1]);
        Livewire::test(Overview::class)->assertDontSee('Sucursal privada')->assertViewHas('rows', fn ($rows): bool => $rows->count() === 1);
        $this->withSession(['sucursal_activa' => $branch])->get(route('external-audits.dashboard'))->assertForbidden();
    }

    public function test_external_auditor_cannot_save_a_verdict_while_ipsa_remains_oos(): void
    {
        $this->actingAs($this->auditor)->patch(route('pyp-audits.update', $this->container), [
            'estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'Intento sin liberación IPSA',
        ])->assertSessionHasErrors('pyp');
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'informacion_adicional' => 'Aprobacion anterior']);
    }

    public function test_activity_notifications_respect_module_branch_and_personal_audiences(): void
    {
        $permitted = User::create(['username' => 'QA_activity_'.uniqid(), 'email' => uniqid().'@example.test',
            'password' => Hash::make('Secret123!'), 'rol_id' => 3, 'sucursal_id' => $this->branch,
            'estado_id' => 1, 'permissions' => ['repuestos.view']]);
        $denied = User::create(['username' => 'QA_denied_'.uniqid(), 'email' => uniqid().'@example.test',
            'password' => Hash::make('Secret123!'), 'rol_id' => 3, 'sucursal_id' => $this->branch,
            'estado_id' => 1, 'permissions' => ['containers.view']]);
        $outsideBranch = DB::table('sucursales')->insertGetId(['nombre' => 'QA Otra '.uniqid(), 'ubicacion' => 'Temporal', 'estado_id' => 1]);
        $outside = User::create(['username' => 'QA_outside_'.uniqid(), 'email' => uniqid().'@example.test',
            'password' => Hash::make('Secret123!'), 'rol_id' => 3, 'sucursal_id' => $outsideBranch,
            'estado_id' => 1, 'permissions' => ['repuestos.view']]);
        $superadmin = User::create(['username' => 'QA_super_'.uniqid(), 'email' => uniqid().'@example.test',
            'password' => Hash::make('Secret123!'), 'rol_id' => 1, 'sucursal_id' => $outsideBranch, 'estado_id' => 1]);
        $notifications = app(SystemNotifications::class);
        $notifications->activity('repuestos', 'Registro actualizado', 'Sellador de prueba',
            [['field' => 'Precio', 'before' => '20', 'after' => '25']], [$this->branch], $this->admin);
        $recipients = DB::table('system_notifications')->where('event_key', 'like', 'activity:%')
            ->where('branch_id', $this->branch)->where('body', 'like', 'Sellador de prueba%')->pluck('user_id')->all();
        $this->assertContains($permitted->id, $recipients);
        $this->assertContains($superadmin->id, $recipients);
        foreach ([$denied, $outside, $this->mechanic, $this->other, $this->auditor] as $excluded) {
            $this->assertNotContains($excluded->id, $recipients);
        }
        $this->assertSame(1, $notifications->inbox($permitted, $this->branch)->count());
        $id = (int) $notifications->inbox($permitted, $this->branch)->value('n.id');
        $this->actingAs($permitted)->get('/notificaciones/'.$id.'/detalle')->assertOk()
            ->assertSee('Sellador de prueba')->assertSee($this->admin->username);
        $permitted->update(['permissions' => ['containers.view']]);
        $this->assertSame(0, $notifications->inbox($permitted->fresh(), $this->branch)->count());
        $this->actingAs($permitted->fresh())->get('/notificaciones/'.$id.'/detalle')->assertNotFound();
    }

    public function test_bell_preview_separates_the_actor_and_summarizes_without_exposing_changes(): void
    {
        $preview = app(SystemNotifications::class)->bellPreview((object) [
            'event_key' => 'container-status:legacy', 'details' => null, 'title' => 'Estado del contenedor actualizado',
            'body' => "SUDU0000034\nModificado por: Ioni\nUnidad: Pendiente PTI → OK",
        ]);
        $this->assertSame('Ioni', $preview['actor']);
        $this->assertSame('Se actualizó el estado del contenedor SUDU0000034.', $preview['description']);
        $this->assertStringNotContainsString('Pendiente PTI', $preview['description']);
        $preview = app(SystemNotifications::class)->bellPreview((object) [
            'event_key' => 'activity:example', 'title' => 'Contenedor trasladado',
            'details' => json_encode(['actor' => 'Autor original', 'subject' => 'Equipo'], JSON_THROW_ON_ERROR),
            'body' => 'Equipo · Autor original',
        ]);
        $this->assertSame(['actor' => 'Autor original', 'description' => 'Se trasladó el contenedor Equipo.'], $preview);
    }

    public function test_clearing_the_bell_keeps_unread_notifications_in_the_panel_and_other_users_bells(): void
    {
        $service = app(SystemNotifications::class);
        $service->activity('containers', 'Aviso anterior', 'Equipo de prueba',
            [['field' => 'Dato', 'before' => 'Anterior', 'after' => 'Nuevo']], [$this->branch], $this->admin);
        $this->actingAs($this->admin)->withSession(['sucursal_activa' => $this->branch]);
        $before = $service->inbox($this->admin, $this->branch)->pluck('n.id')->all();
        $this->assertNotEmpty($before);
        Livewire::test(NotificationBell::class)->call('clearBell')->assertDontSee('Aviso anterior');
        $this->assertSame($before, $service->inbox($this->admin, $this->branch)->pluck('n.id')->all());
        $records = DB::table('system_notifications')->whereIn('id', $before)->get();
        foreach ($records as $record) {
            $this->assertNotNull($record->bell_dismissed_at);
            $this->assertNull($record->read_at);
            $this->assertNull($record->archived_at);
        }
        $this->assertSame(0, DB::table('system_notifications')->where('user_id', '!=', $this->admin->id)
            ->whereNotNull('bell_dismissed_at')->count());
        $service->activity('containers', 'Aviso nuevo', 'Otro equipo', [], [$this->branch], $this->admin);
        Livewire::test(NotificationBell::class)->assertSee('Aviso nuevo')->assertDontSee('Aviso anterior');
    }

    public function test_activity_notifications_roll_back_with_the_source_operation(): void
    {
        $before = DB::table('system_notifications')->count();
        DB::beginTransaction();
        app(SystemNotifications::class)->activity('containers', 'Cambio temporal', 'Registro temporal',
            [['field' => 'Nombre', 'before' => 'Anterior', 'after' => 'Nuevo']], [$this->branch], $this->admin);
        $this->assertGreaterThan($before, DB::table('system_notifications')->count());
        DB::rollBack();
        $this->assertSame($before, DB::table('system_notifications')->count());
    }

    public function test_user_activity_does_not_expose_passwords_and_ignores_unchanged_values(): void
    {
        $notifications = app(SystemNotifications::class);
        $before = $this->other->toArray();
        $before['external_branches'] = [];
        $count = DB::table('system_notifications')->count();
        $notifications->userChanged($this->admin, $this->other, $before, 'Usuario actualizado');
        $this->assertSame($count, DB::table('system_notifications')->count());
        $notifications->userChanged($this->admin, $this->other, $before, 'Usuario actualizado', true);
        $details = DB::table('system_notifications')->where('category', 'users')->where('branch_id', $this->branch)
            ->where('body', 'like', $this->other->username.'%')->value('details');
        $this->assertStringContainsString('valor protegido', $details);
        $this->assertStringNotContainsString($this->other->password, $details);
        $this->assertStringNotContainsString('Secret123!', $details);
    }

    private function start(): int
    {
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container,
            'diagnosis' => 'Golpe en el panel lateral derecho.',
            'evidence_before' => [UploadedFile::fake()->image('antes.jpg')]])->assertRedirect()->assertSessionHasNoErrors();

        return (int) DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['version' => 1, 'action' => 'save', 'diagnosis' => 'Golpe en el panel lateral derecho.',
            'work_performed' => 'Se reparo y sello el panel. Pruebas satisfactorias.', 'repair_service_id' => $this->service,
            'materials' => [['part_id' => $this->part, 'quantity' => '1.500']]], $changes);
    }

    public function test_initial_summary_and_assessment_do_not_start_work_or_change_the_previous_approval(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update(['observaciones_unidad' => 'Falla reportada de unidad', 'observaciones_box' => 'Panel lateral golpeado']);
        $url = route('maintenance.create', ['container_id' => $this->container]);
        $response = $this->get($url)->assertOk()->assertSee('Evaluación inicial')->assertSee('Fotos de evidencia')->assertDontSee('name="visible_damage"', false)
            ->assertSee('name="container_id" value="'.$this->container.'"', false)
            ->assertDontSee('<select name="container_id"', false)->assertSee('enctype="multipart/form-data"', false);
        $summary = $this->getJson($url)->assertOk()->assertJsonPath('unit_notes', 'Falla reportada de unidad')
            ->assertJsonPath('box_notes', 'Panel lateral golpeado')->assertJsonStructure(['name', 'location', 'unit_status', 'box_status', 'evaluation_url']);
        $this->assertArrayNotHasKey('transportista', $summary->json());
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Aprobado']);
        $this->get('/mantenimiento/ordenes')->assertOk()->assertSee('data-maintenance-summary', false);
        $this->getJson(route('maintenance.create', ['container_id' => 0]))->assertNotFound();
    }

    public function test_pending_unit_or_box_blocks_starting_from_the_menu_and_direct_requests(): void
    {
        $container = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($container->bloque_id);
        foreach (['estatus_unidad_id' => [1, 'Pendiente PTI'], 'estatus_box_id' => [2, 'Pendiente Revision']] as $column => [$type, $name]) {
            $pending = DB::table('estatus_contenedores')->insertGetId(['nombre' => $name, 'tipo_estatus_id' => $type, 'estado_id' => 1]);
            DB::table('contenedores')->where('id', $this->container)->update([$column => $pending]);
            $this->get(route('maintenance.create', ['container_id' => $this->container]))->assertNotFound();
            $this->getJson(route('maintenance.create', ['container_id' => $this->container]))->assertNotFound();
            $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Reparar panel'])
                ->assertSessionHasErrors('container_id');
            $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
            Livewire::test(InventoryTable::class, ['maintenanceMode' => true])
                ->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
                ->assertViewHas('containers', fn (array $rows): bool => ! $rows[0]->maintenance_can_start
                    && $rows[0]->maintenance_actions['maintenance'] === null)
                ->call('setViewMode', 'yard3d')->assertViewHas('yardScene', function (array $scene): bool {
                    $item = collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers'])->firstWhere('id', $this->container);

                    return $item['actions']['maintenance'] === null;
                });
            DB::table('contenedores')->where('id', $this->container)->update([$column => $container->{$column}]);
        }
        $this->get(route('maintenance.create', ['container_id' => $this->container]))->assertOk();
        $this->assertGreaterThan(0, $this->start());
    }

    public function test_pending_status_does_not_block_saving_or_submitting_work_that_already_started(): void
    {
        $job = $this->start();
        $pending = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'Pendiente PTI', 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_unidad_id' => $pending]);
        $this->get(route('maintenance.show', $job))->assertOk();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload())->assertSessionHasNoErrors();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload([
            'version' => 2, 'action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')],
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $job, 'status' => 'pending_review']);
    }

    public function test_initial_draft_rejects_invalid_uploads_and_empty_saves(): void
    {
        $this->get(route('maintenance.create', ['container_id' => $this->container]))->assertOk()
            ->assertSee('container-form-timeline', false)->assertSee('data-maintenance-tab="0"', false)
            ->assertSee('name="diagnosis"', false)->assertSee('name="evidence_before[]"', false)
            ->assertSee('Siguiente')->assertSee('data-maintenance-save hidden', false)->assertSee('data-maintenance-exit', false)
            ->assertSee('container-exit-dialog')->assertSee('¿Salir sin guardar?')->assertDontSee('Iniciar reparación');
        $payload = ['container_id' => $this->container, 'diagnosis' => 'Inspeccion inicial del panel lateral.'];
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container])->assertSessionHasErrors('work');
        $this->post('/mantenimiento/ordenes', $payload + ['evidence_before' => [UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf')]])
            ->assertSessionHasErrors('evidence_before.0');
        $this->post('/mantenimiento/ordenes', $payload + ['evidence_before' => [UploadedFile::fake()->image('grande.jpg')->size(2049)]])
            ->assertSessionHasErrors('evidence_before.0');
        $this->post('/mantenimiento/ordenes', $payload + ['evidence_before' => array_map(fn ($i) => UploadedFile::fake()->image('foto-'.$i.'.jpg'), range(1, 6))])
            ->assertSessionHasErrors('evidence_before');
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Aprobado']);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $id = $this->start();
        $this->assertDatabaseHas('maintenance_evidence', ['job_id' => $id, 'stage' => 'before', 'uploaded_by' => $this->mechanic->id]);
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'in_progress']);
        $this->getJson(route('maintenance.create', ['container_id' => $this->container]))->assertNotFound();
    }

    public function test_partial_diagnosis_can_be_saved_as_in_progress_without_photos_but_cannot_be_submitted(): void
    {
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'action' => 'save', 'active_step' => 0, 'diagnosis' => 'Golpe'])
            ->assertSessionHasNoErrors();
        $id = (int) DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'diagnosis' => 'Golpe', 'status' => 'in_progress']);
        $this->assertSame(0, DB::table('maintenance_evidence')->where('job_id', $id)->count());
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit', 'diagnosis' => 'Golpe']))->assertSessionHasErrors('diagnosis');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit']))->assertSessionHasErrors('evidence');
        $this->assertDatabaseMissing('maintenance_review_requests', ['job_id' => $id]);
    }

    public function test_new_form_saves_later_steps_and_keeps_the_selected_step_without_initial_evaluation(): void
    {
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'action' => 'save', 'active_step' => 1,
            'repair_service_id' => $this->service, 'materials' => [['part_id' => $this->part, 'quantity' => '2.000']]])->assertSessionHasNoErrors();
        $id = (int) DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'diagnosis' => '', 'repair_service_id' => $this->service, 'repair_type' => 'Soldadura y sellado de panel', 'status' => 'in_progress']);
        $this->assertDatabaseHas('maintenance_materials', ['job_id' => $id, 'part_id' => $this->part, 'quantity' => '2.000']);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Asignado']);
        $this->get('/mantenimiento/ordenes/'.$id.'?step=1')->assertOk()->assertSee('data-initial-step="1"', false);
    }

    public function test_invalid_material_on_first_save_rolls_back_the_job_audit_and_photos(): void
    {
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'action' => 'save', 'repair_service_id' => $this->service,
            'materials' => [['part_id' => 0, 'quantity' => '1']],
            'evidence_before' => [UploadedFile::fake()->image('antes.jpg')]])->assertSessionHasErrors('materials');
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Aprobado']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_new_repair_can_save_only_a_photo_as_an_in_progress_draft(): void
    {
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'action' => 'save',
            'evidence_after' => [UploadedFile::fake()->image('avance.jpg')]])->assertSessionHasNoErrors();
        $id = (int) DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'in_progress', 'diagnosis' => '']);
        $this->assertDatabaseHas('maintenance_evidence', ['job_id' => $id, 'stage' => 'after', 'original_name' => 'avance.jpg']);
    }

    public function test_first_save_can_submit_the_complete_repair_with_both_photo_stages(): void
    {
        $this->post('/mantenimiento/ordenes', $this->payload(['container_id' => $this->container, 'action' => 'submit', 'active_step' => 2,
            'evidence_before' => [UploadedFile::fake()->image('antes.jpg')],
            'evidence_after' => [UploadedFile::fake()->image('despues.jpg')]]))->assertSessionHasNoErrors();
        $id = (int) DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'pending_review']);
        $this->assertDatabaseHas('maintenance_review_requests', ['job_id' => $id, 'status' => 'pending']);
        $this->assertSame(2, DB::table('maintenance_evidence')->where('job_id', $id)->count());
    }

    public function test_diagnosis_is_limited_to_1000_characters_when_starting_and_updating_a_repair(): void
    {
        $this->postJson('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => str_repeat('a', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('diagnosis');
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => str_repeat('a', 1000),
            'evidence_before' => [UploadedFile::fake()->image('antes.jpg')]])->assertRedirect()->assertSessionHasNoErrors();
        $job = DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
        $this->patchJson('/mantenimiento/ordenes/'.$job, $this->payload(['diagnosis' => str_repeat('b', 1001)]))
            ->assertUnprocessable()->assertJsonValidationErrors('diagnosis');
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['diagnosis' => str_repeat('b', 1000)]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $job, 'diagnosis' => str_repeat('b', 1000)]);
    }

    public function test_initial_evaluation_is_editable_in_the_timeline_with_repair_type_and_materials(): void
    {
        $id = $this->start();
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertSee('container-form-timeline', false)
            ->assertSee('data-initial-step="1"', false)->assertSee('Tipo de reparación')->assertSee('Resultado de la reparación')
            ->assertSee('Fotos de evidencia final')->assertSee('name="diagnosis"', false)
            ->assertSee('data-photo-carousel', false)->assertSee('aria-label="Foto anterior"', false)->assertSee('aria-label="Foto siguiente"', false)
            ->assertSee('name="evidence_before[]"', false)->assertDontSee('name="evidence_test[]"', false);
        $replacement = DB::table('repair_services')->insertGetId(['code' => 'QA-'.uniqid(), 'name' => 'Cambio de panel', 'unit' => 'Servicio', 'status' => 1]);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['active_step' => 0, 'repair_service_id' => $replacement,
            'diagnosis' => 'Panel lateral abollado junto a la puerta derecha.',
            'evidence_before' => [UploadedFile::fake()->image('detalle-inicial.jpg', 25, 25)]]))
            ->assertSessionHasNoErrors()->assertRedirect(route('maintenance.show', ['job' => $id, 'step' => 0]));
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'repair_type' => 'Cambio de panel', 'diagnosis' => 'Panel lateral abollado junto a la puerta derecha.']);
        $this->assertSame(2, DB::table('maintenance_evidence')->where('job_id', $id)->where('stage', 'before')->count());
        $event = DB::table('maintenance_events')->where('job_id', $id)->where('action', 'saved')->first();
        $this->assertSame('Golpe en el panel lateral derecho.', json_decode($event->before_data, true)['job']['diagnosis']);
        $this->assertSame('Panel lateral abollado junto a la puerta derecha.', json_decode($event->after_data, true)['job']['diagnosis']);
        $this->assertDatabaseHas('maintenance_materials', ['job_id' => $id, 'part_id' => $this->part]);
    }

    public function test_return_to_inventory_preserves_context_through_summary_creation_and_saves(): void
    {
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        $back = ['patio_id' => (string) $block->patio_id, 'bloque_id' => (string) $block->id, 'search' => $record->nombre,
            'per_page' => '5', 'page' => '2', 'sort' => 'nombre', 'order' => 'asc', 'vista' => 'yard3d', 'estatus_ipsa' => 'OOS'];
        $url = route('maintenance.create', ['container_id' => $this->container, 'back' => $back]);
        $summary = $this->getJson($url)->assertOk();
        $this->assertSame($url, $summary->json('evaluation_url'));
        $returnUrl = route('maintenance.orders', $back);
        $this->get($url)->assertOk()->assertViewHas('backParams', $back)->assertSee($returnUrl)
            ->assertDontSee('Mecánico responsable')->assertDontSee('Estado del trabajo')->assertDontSee('Ubicación actual');
        $response = $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Panel con golpe.', 'back' => $back, 'active_step' => 0]);
        $id = (int) DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
        $response->assertSessionHasNoErrors()->assertRedirect(route('maintenance.show', ['job' => $id, 'step' => 0, 'back' => $back]));
        $this->get($response->headers->get('Location'))->assertOk()->assertViewHas('backParams', $back)->assertSee($returnUrl)
            ->assertSee('name="back[vista]" value="yard3d"', false)->assertDontSee('Mecánico responsable');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['back' => $back, 'active_step' => 1]))
            ->assertSessionHasNoErrors()->assertRedirect(route('maintenance.show', ['job' => $id, 'step' => 1, 'back' => $back]));
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertViewHas('backParams', ['patio_id' => (string) $block->patio_id, 'bloque_id' => (string) $block->id]);
    }

    public function test_inventory_repair_actions_include_the_current_filters_and_view(): void
    {
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        $id = $this->start();
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->set('search', $record->nombre)->call('selectPerPage', 5)->call('setViewMode', 'yard3d')
            ->assertViewHas('yardScene', function (array $scene) use ($block, $record, $id): bool {
                $item = collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers'])->firstWhere('id', $this->container);
                parse_str(parse_url($item['actions']['maintenance'], PHP_URL_QUERY), $query);

                return str_contains($item['actions']['maintenance'], '/mantenimiento/ordenes/'.$id)
                    && $query['back']['patio_id'] === (string) $block->patio_id && $query['back']['bloque_id'] === (string) $block->id
                    && $query['back']['search'] === $record->nombre && $query['back']['per_page'] === '5' && $query['back']['vista'] === 'yard3d';
            });
    }

    public function test_return_context_ignores_external_destinations_and_nested_values(): void
    {
        $this->get(route('maintenance.create', ['container_id' => $this->container,
            'back' => ['url' => 'https://example.com', 'search' => ['invalid'], 'vista' => 'table']]))->assertOk()
            ->assertViewHas('backParams', ['vista' => 'table'])->assertDontSee('https://example.com');
    }

    public function test_repair_type_can_be_pending_in_a_draft_but_is_required_for_review(): void
    {
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['repair_service_id' => null]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'repair_type' => null, 'version' => 2]);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2, 'action' => 'submit', 'repair_service_id' => null]))->assertSessionHasErrors('repair_service_id');
        $this->assertDatabaseMissing('maintenance_review_requests', ['job_id' => $id]);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2, 'action' => 'submit',
            'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'pending_review', 'repair_type' => 'Soldadura y sellado de panel']);
    }

    public function test_repair_selector_uses_catalog_ids_and_rejects_free_text_unknown_and_inactive_services(): void
    {
        $inactive = DB::table('repair_services')->insertGetId(['code' => 'QA-'.uniqid(), 'name' => 'Servicio inactivo QA', 'unit' => 'Servicio', 'status' => 2]);
        $this->get(route('maintenance.create', ['container_id' => $this->container]))->assertOk()
            ->assertSee('name="repair_service_id" data-admin-select', false)->assertSee('Soldadura y sellado de panel')
            ->assertDontSee('Servicio inactivo QA')->assertDontSee('name="repair_type"', false);
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'repair_type' => 'Texto libre'])
            ->assertSessionHasErrors('repair_type');
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'repair_service_id' => 999999999])
            ->assertSessionHasErrors('repair_service_id');
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'repair_service_id' => $inactive])
            ->assertSessionHasErrors('repair_service_id');
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['repair_service_id' => $inactive]))->assertSessionHasErrors('repair_service_id');
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'version' => 1, 'repair_service_id' => null]);
    }

    public function test_selected_service_keeps_its_recorded_name_after_catalog_changes(): void
    {
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload())->assertSessionHasNoErrors();
        DB::table('repair_services')->where('id', $this->service)->update(['name' => 'Nombre nuevo del servicio', 'status' => 2]);
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertSee('Soldadura y sellado de panel')->assertSee('Inactiva')->assertDontSee('Nombre nuevo del servicio');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'repair_service_id' => $this->service, 'repair_type' => 'Soldadura y sellado de panel']);
    }

    public function test_catalog_seeding_is_repeatable_and_preserves_existing_records(): void
    {
        $beforePart = DB::table('repair_parts')->where('id', $this->part)->first();
        $beforeService = DB::table('repair_services')->where('id', $this->service)->first();
        $this->seed(MaintenanceCatalogSeeder::class);
        $counts = [DB::table('repair_parts')->count(), DB::table('repair_services')->count()];
        $this->seed(MaintenanceCatalogSeeder::class);
        $this->assertSame($counts, [DB::table('repair_parts')->count(), DB::table('repair_services')->count()]);
        $this->assertEquals($beforePart, DB::table('repair_parts')->where('id', $this->part)->first());
        $this->assertEquals($beforeService, DB::table('repair_services')->where('id', $this->service)->first());
        $this->assertDatabaseHas('repair_services', ['name' => 'Cambio de panel', 'status' => 1]);
        $this->assertDatabaseHas('repair_parts', ['name' => 'Lamina de acero', 'unit' => 'Lamina', 'status' => 1]);
    }

    public function test_reference_prices_are_server_snapshots_and_survive_catalog_changes(): void
    {
        DB::table('repair_services')->where('id', $this->service)->update(['reference_cost' => '125.25', 'currency' => 'GTQ', 'unit' => 'Hora']);
        DB::table('repair_parts')->where('id', $this->part)->update(['reference_cost' => '20.50', 'currency' => 'USD']);
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['service_quantity' => '2.500', 'service_unit_cost' => '0.01',
            'materials' => [['part_id' => $this->part, 'quantity' => '1.500', 'unit_cost' => '0.01']]]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'service_unit_cost' => '125.25', 'service_currency' => 'GTQ', 'service_unit' => 'Servicio', 'service_quantity' => '1.000']);
        $this->assertDatabaseHas('maintenance_materials', ['job_id' => $id, 'unit_cost' => '20.50', 'currency' => 'USD']);
        DB::table('repair_services')->where('id', $this->service)->update(['reference_cost' => '999.99', 'currency' => 'USD', 'unit' => 'Servicio']);
        DB::table('repair_parts')->where('id', $this->part)->update(['reference_cost' => '888.88', 'currency' => 'GTQ']);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2, 'service_quantity' => '3']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'service_unit_cost' => '125.25', 'service_currency' => 'GTQ', 'service_unit' => 'Servicio', 'service_quantity' => '1.000']);
        $this->assertDatabaseHas('maintenance_materials', ['job_id' => $id, 'unit_cost' => '20.50', 'currency' => 'USD']);
        $response = $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertSee('data-admin-search', false);
        $selectedService = $response->viewData('repairServices')->firstWhere('id', $this->service);
        $selectedPart = $response->viewData('parts')->firstWhere('id', $this->part);
        $this->assertSame('125.25', $selectedService->reference_cost);
        $this->assertSame('Servicio', $selectedService->unit);
        $response->assertDontSee('name="service_quantity"', false)->assertSee('Tarifa del servicio');
        $this->assertSame('20.50', $selectedPart->reference_cost);
        $this->assertSame('USD', $selectedPart->currency);
    }

    public function test_unknown_prices_stay_null_and_submitted_service_quantities_are_ignored(): void
    {
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['service_quantity' => '999']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'service_quantity' => '1.000']);
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'service_unit_cost' => null, 'service_currency' => 'GTQ']);
        $this->assertDatabaseHas('maintenance_materials', ['job_id' => $id, 'unit_cost' => null, 'currency' => 'GTQ']);
        DB::table('repair_services')->where('id', $this->service)->update(['reference_cost' => '100.00']);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'service_unit_cost' => null]);
    }

    public function test_dollar_prices_keep_original_currency_and_one_saved_exchange_rate(): void
    {
        DB::table('repair_parts')->where('id', $this->part)->update(['reference_cost' => '20.50', 'currency' => 'USD']);
        $quote = app(MaintenanceExchangeRate::class)->quote();
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['exchange_quote' => 'tampered']))->assertSessionHasErrors('exchange_quote');
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'version' => 1, 'usd_gtq_rate' => null]);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['exchange_quote' => $quote['token'], 'usd_gtq_rate' => '0.01']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'usd_gtq_rate' => '7.650000', 'exchange_rate_source' => MaintenanceExchangeRate::SOURCE]);
        $this->assertDatabaseHas('maintenance_materials', ['job_id' => $id, 'currency' => 'USD', 'unit_cost' => '20.50']);
        Cache::flush();
        Http::fake([MaintenanceExchangeRate::SOURCE => Http::response('<root><VarDolar><fecha>'.now('America/Guatemala')->format('d/m/Y').'</fecha><referencia>8.0000</referencia></VarDolar></root>')]);
        $newQuote = app(MaintenanceExchangeRate::class)->quote();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2, 'exchange_quote' => $newQuote['token']]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'usd_gtq_rate' => '7.650000']);
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertSee('data-exchange-rate="7.650000"', false);
    }

    public function test_reference_price_seed_is_repeatable_preserves_prices_and_checks_units(): void
    {
        $this->seed(MaintenanceCatalogSeeder::class);
        DB::table('repair_services')->where('code', 'RPR-BASE-001')->update(['reference_cost' => '999.00']);
        DB::table('repair_parts')->where('code', 'REP-BASE-002')->update(['reference_cost' => null, 'unit' => 'Metro']);
        DB::table('repair_parts')->where('code', 'REP-BASE-011')->update(['reference_cost' => null]);
        $this->seed(MaintenanceReferencePriceSeeder::class);
        $snapshot = DB::table('repair_parts')->where('code', 'REP-BASE-011')->first();
        $this->seed(MaintenanceReferencePriceSeeder::class);
        $this->assertEquals($snapshot, DB::table('repair_parts')->where('code', 'REP-BASE-011')->first());
        $this->assertDatabaseHas('repair_services', ['code' => 'RPR-BASE-001', 'reference_cost' => '999.00']);
        $this->assertDatabaseHas('repair_parts', ['code' => 'REP-BASE-002', 'reference_cost' => null]);
        $this->assertDatabaseHas('repair_parts', ['code' => 'REP-BASE-011', 'reference_cost' => '60.00']);
        $this->assertStringContainsString('seir.com.gt', $snapshot->description);
    }

    public function test_remaining_materials_receive_approximate_prices_in_their_catalog_units(): void
    {
        $this->seed(MaintenanceCatalogSeeder::class);
        $prices = [4 => '300.00', 5 => '25.00', 12 => '58.12', 13 => '65.00', 15 => '2.53', 16 => '400.00'];
        foreach ($prices as $index => $price) {
            DB::table('repair_parts')->where('code', 'REP-BASE-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT))->update(['reference_cost' => null, 'description' => null]);
        }
        $this->seed(MaintenanceReferencePriceSeeder::class);
        foreach ($prices as $index => $price) {
            $part = DB::table('repair_parts')->where('code', 'REP-BASE-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT))->first();
            $this->assertSame($price, $part->reference_cost);
            $this->assertSame('GTQ', $part->currency);
            $this->assertLessThanOrEqual(255, mb_strlen($part->description));
        }
    }

    public function test_up_to_five_photos_are_allowed_in_each_stage_without_requiring_all_five(): void
    {
        $photos = fn (int $count): array => array_map(fn ($i) => UploadedFile::fake()->image('antes-'.$i.'.jpg', 20 + $i, 20), range(1, $count));
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Panel lateral con dano para reparar.',
            'evidence_before' => $photos(6)])->assertSessionHasErrors('evidence_before');
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Panel lateral con dano para reparar.',
            'evidence_before' => $photos(5)])->assertSessionHasNoErrors();
        $id = DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['evidence_after' => $photos(5)]))->assertSessionHasNoErrors();
        $this->assertSame(10, DB::table('maintenance_evidence')->where('job_id', $id)->count());
        foreach (['before', 'after'] as $stage) {
            $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2, 'evidence_'.$stage => $photos(1)]))->assertSessionHasErrors('evidence');
        }
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2, 'action' => 'submit']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'pending_review']);
    }

    public function test_photo_previews_are_private_images_and_remain_branch_scoped(): void
    {
        $id = $this->start();
        $photo = DB::table('maintenance_evidence')->where('job_id', $id)->first();
        $url = route('maintenance.evidence', ['job' => $id, 'evidence' => $photo->id, 'inline' => 1]);
        $response = $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->actingAs($this->auditor)->get($url)->assertForbidden();
    }

    public function test_initial_photos_reject_identical_content_even_when_renamed_but_allow_different_images_with_the_same_name(): void
    {
        $original = UploadedFile::fake()->image('original.jpg', 20, 20);
        $copy = UploadedFile::fake()->createWithContent('renombrada.jpg', file_get_contents($original->getRealPath()));
        $payload = ['container_id' => $this->container, 'diagnosis' => 'Inspeccion del panel antes de reparar.'];
        $this->postJson('/mantenimiento/ordenes', $payload + ['evidence_before' => [$original, $copy]])
            ->assertUnprocessable()->assertJsonValidationErrors('evidence_before.1');
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Aprobado']);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->post('/mantenimiento/ordenes', $payload + ['evidence_before' => [
            UploadedFile::fake()->image('foto.jpg', 20, 20), UploadedFile::fake()->image('foto.jpg', 30, 30),
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $job = DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
        $this->assertSame(2, DB::table('maintenance_evidence')->where('job_id', $job)->count());
    }

    public function test_failed_initial_photo_storage_rolls_back_the_job_and_cleans_previously_uploaded_photos(): void
    {
        $failure = \Mockery::mock(UploadedFile::class);
        $failure->shouldReceive('store')->once()->andReturn(false);
        try {
            app(MaintenanceWorkflow::class)->start($this->mechanic, $this->branch,
                ['container_id' => $this->container, 'diagnosis' => 'Diagnostico de prueba con fotos.'],
                [UploadedFile::fake()->image('primera.jpg'), $failure]);
            $this->fail('La evidencia fallida debe impedir iniciar el trabajo.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No se pudo guardar la evidencia', $exception->getMessage());
        }
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Aprobado']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_mechanic_is_separated_from_external_audits_and_internal_operations(): void
    {
        $this->get('/')->assertRedirect(route('maintenance.orders'));
        $this->get('/seguimiento-pyp')->assertRedirect(route('maintenance.orders'));
        $this->get('/mantenimiento/ordenes')->assertOk()->assertDontSee('Nuevo trabajo')->assertDontSee('Seguimiento PYP');
        $this->get('/contenedores/'.$this->container.'/pyp')->assertForbidden();
        $this->patch('/contenedores/'.$this->container.'/pyp', ['estatus_pyp' => 'Aprobado'])->assertForbidden();
        $this->post('/contenedores/'.$this->container.'/pyp/asignar')->assertForbidden();
        $this->get('/contenedores/'.$this->container.'/editar')->assertForbidden();
        $this->post('/despachos', ['contenedor_id' => $this->container])->assertForbidden();
        Livewire::test(InventoryTable::class)->assertForbidden();
        $this->expectException(HttpException::class);
        app(PypAuditService::class)->update($this->mechanic, $this->branch, $this->container, ['estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'No autorizado']);
    }

    public function test_start_preserves_previous_audit_and_states_and_prevents_duplicate_work(): void
    {
        $before = DB::table('contenedores')->find($this->container);
        $id = $this->start();
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Asignado']);
        $snapshot = json_decode(DB::table('maintenance_events')->where('job_id', $id)->value('after_data'), true);
        $this->assertSame('Aprobado', $snapshot['previous_audit']['estatus_pyp']);
        $after = DB::table('contenedores')->find($this->container);
        $this->assertEquals([$before->estatus_unidad_id, $before->estatus_box_id, $before->estatus_ipsa_id], [$after->estatus_unidad_id, $after->estatus_box_id, $after->estatus_ipsa_id]);
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Otro diagnostico valido'])->assertSessionHasErrors('container_id');
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertSee('Materiales')->assertSee('Resultado')->assertSee('Solicitar revisión');
    }

    public function test_save_materials_private_evidence_and_submit_only_once_without_releasing_container(): void
    {
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload([
            'evidence_before' => [UploadedFile::fake()->image('antes.jpg')],
            'evidence_after' => [UploadedFile::fake()->image('despues.jpg')],
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_materials', ['job_id' => $id, 'part_id' => $this->part, 'quantity' => '1.500']);
        $file = DB::table('maintenance_evidence')->where('job_id', $id)->first();
        Storage::disk('local')->assertExists($file->path);
        $this->get('/mantenimiento/ordenes/'.$id.'/evidencias/'.$file->id)->assertOk()->assertDownload('antes.jpg');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2, 'action' => 'submit']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'pending_review']);
        $this->assertDatabaseHas('maintenance_review_requests', ['job_id' => $id, 'branch_id' => $this->branch, 'requested_by' => $this->mechanic->id, 'status' => 'pending']);
        $this->assertDatabaseHas('contenedores', ['id' => $this->container, 'estatus_ipsa_id' => $this->oosStatus]);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Asignado']);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 3, 'action' => 'submit']))->assertSessionHasErrors('work');
        $this->assertSame(1, DB::table('maintenance_review_requests')->where('job_id', $id)->count());
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertDontSee('name="action"', false);
    }

    public function test_submission_requires_work_and_photos_without_a_justification_bypass(): void
    {
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit', 'work_performed' => '  ']))->assertSessionHasErrors('work_performed');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit']))->assertSessionHasErrors('evidence');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit', 'visible_damage' => false]))->assertSessionHasErrors('evidence');
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'in_progress', 'version' => 1]);
        $this->assertDatabaseMissing('maintenance_review_requests', ['job_id' => $id]);
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertDontSee('name="visible_damage"', false);
        DB::table('maintenance_jobs')->where('id', $id)->update(['evidence_exception' => 'Justificacion anterior que no debe omitir las fotos.']);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit',
            'evidence_exception' => 'No se tomaron fotos antes; se requiere verificacion fisica.']))->assertSessionHasErrors('evidence');
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'in_progress', 'version' => 1]);
        $this->assertDatabaseMissing('maintenance_review_requests', ['job_id' => $id]);
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertDontSee('name="evidence_exception"', false);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit',
            'evidence_after' => [UploadedFile::fake()->image('despues.jpg')]]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'pending_review']);
    }

    public function test_both_photo_stages_are_mandatory_and_pdfs_do_not_count_as_photos(): void
    {
        $id = $this->start();
        DB::table('maintenance_evidence')->where('job_id', $id)->delete();
        foreach ([
            [['evidence_after' => [UploadedFile::fake()->image('despues.jpg')]], 'evidence'],
            [['evidence_before' => [UploadedFile::fake()->image('antes.jpg')], 'evidence_after' => [UploadedFile::fake()->create('despues.pdf', 10, 'application/pdf')]], 'evidence_after.0'],
            [['evidence_before' => [UploadedFile::fake()->create('antes.pdf', 10, 'application/pdf')], 'evidence_after' => [UploadedFile::fake()->image('despues.jpg')]], 'evidence_before.0'],
        ] as [$uploads, $error]) {
            $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit'] + $uploads))
                ->assertSessionHasErrors($error);
            $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'in_progress', 'version' => 1]);
            $this->assertDatabaseMissing('maintenance_review_requests', ['job_id' => $id]);
        }
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit',
            'evidence_before' => [UploadedFile::fake()->image('antes.jpg')],
            'evidence_after' => [UploadedFile::fake()->image('despues.jpg')],
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'pending_review']);
    }

    private function anotherContainer(int $position): int
    {
        $container = (array) DB::table('contenedores')->find($this->container);
        unset($container['id']);
        $container['nombre'] = 'MNTU'.random_int(1000000, 9999999);
        $container['posicion'] = $position;
        $container['current_ingress_cycle_id'] = null;
        $id = DB::table('contenedores')->insertGetId($container);
        $cycle = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        unset($cycle['id']);
        $cycle['container_id'] = $id;
        $cycleId = DB::table('container_ingress_cycles')->insertGetId($cycle);
        DB::table('contenedores')->where('id', $id)->update(['current_ingress_cycle_id' => $cycleId]);

        return $id;
    }

    public function test_parallel_work_is_allowed_for_distinct_containers_but_not_duplicate_work(): void
    {
        $first = $this->start();
        $this->actingAs($this->other)->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Intento de abrir otra reparación.'])
            ->assertSessionHasErrors('container_id');
        $secondContainer = $this->anotherContainer(2);
        $this->post('/mantenimiento/ordenes', ['container_id' => $secondContainer, 'diagnosis' => 'Reparación en otro contenedor.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $first, 'mechanic_id' => $this->mechanic->id, 'status' => 'in_progress']);
        $this->assertDatabaseHas('maintenance_jobs', ['container_id' => $secondContainer, 'mechanic_id' => $this->other->id, 'status' => 'in_progress']);
        $this->get('/mantenimiento/ordenes/'.$first)->assertOk()->assertSee('Solo lectura: asignado a otro mecánico')->assertDontSee('name="action"', false);
        $this->patch('/mantenimiento/ordenes/'.$first, $this->payload())->assertForbidden();
        $thirdContainer = $this->anotherContainer(3);
        $this->actingAs($this->mechanic)->post('/mantenimiento/ordenes', ['container_id' => $thirdContainer, 'diagnosis' => 'Otro trabajo del mismo responsable.'])->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('maintenance_jobs')->where('branch_id', $this->branch)->where('status', 'in_progress')->count());
    }

    public function test_review_folios_do_not_use_ids_and_roll_back_without_consuming_a_number(): void
    {
        $first = $this->start();
        $last = (int) DB::table('maintenance_counters')->where('name', 'review_request')->value('last_value');
        $this->patch('/mantenimiento/ordenes/'.$first, $this->payload(['action' => 'submit']))->assertSessionHasErrors('evidence');
        $this->assertSame($last, (int) DB::table('maintenance_counters')->where('name', 'review_request')->value('last_value'));
        DB::beginTransaction();
        $this->patch('/mantenimiento/ordenes/'.$first, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_review_requests', ['job_id' => $first, 'folio' => $last + 1]);
        DB::rollBack();
        $this->assertSame($last, (int) DB::table('maintenance_counters')->where('name', 'review_request')->value('last_value'));
        $this->patch('/mantenimiento/ordenes/'.$first, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $review = DB::table('maintenance_review_requests')->where('job_id', $first)->first();
        $this->assertSame($last + 1, (int) $review->folio);
        $response = $this->get('/mantenimiento/ordenes/'.$first)->assertOk()
            ->assertSee('Solicitud '.str_pad((string) $review->folio, 3, '0', STR_PAD_LEFT))
            ->assertSee('Pendiente de revisión')->assertSee('Solo lectura: enviado a revisión')
            ->assertDontSee('Solicitud #'.$review->id)->assertDontSee('Consulta solamente')->assertDontSee('mw-notice', false)
            ->assertDontSee('name="action"', false);
        $this->container = $this->anotherContainer(2);
        $second = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$second, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado-2.jpg')]]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_review_requests', ['job_id' => $second, 'folio' => $last + 2]);
    }

    public function test_ownership_branch_and_mechanic_only_access_are_enforced(): void
    {
        $id = $this->start();
        $this->actingAs($this->other)->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertDontSee('name="action"', false);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload())->assertForbidden();
        $this->actingAs($this->admin)->get('/mantenimiento/ordenes/'.$id)->assertForbidden();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload())->assertForbidden();
        $this->admin->update(['permissions' => ['containers.view']]);
        $this->get('/mantenimiento/ordenes')->assertForbidden();
        $this->actingAs($this->auditor)->get('/mantenimiento/ordenes/'.$id)->assertForbidden();
        $this->actingAs($this->mechanic)->withSession(['sucursal_activa' => 0])->get('/mantenimiento/ordenes')->assertForbidden();
    }

    public function test_review_notifications_are_personal_idempotent_and_permission_scoped(): void
    {
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $review = DB::table('maintenance_review_requests')->where('job_id', $job)->first();
        $service = app(SystemNotifications::class);
        $service->reviewRequested($review->id);
        $query = $service->inbox($this->admin, $this->branch)->where('n.job_id', $job);
        $this->assertSame(1, (clone $query)->count());
        $notification = $query->first();
        $this->post(route('notifications.open', $notification->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('notifications.index'))->assertOk()->assertSee('Reparación pendiente de revisión');
        $this->post(route('notifications.open', $notification->id))->assertRedirect(route('notifications.repair', $job));
        $this->assertDatabaseMissing('system_notifications', ['id' => $notification->id, 'read_at' => null]);
        $service->reviewRequested($review->id);
        $this->assertDatabaseMissing('system_notifications', ['id' => $notification->id, 'read_at' => null]);
        $this->get(route('notifications.repair', $job))->assertOk()->assertSee('Evaluación inicial')
            ->assertSee('data-notification-review', false)->assertSee('data-maintenance-tab="2"', false)
            ->assertSee('data-review-gallery', false)->assertSee('data-review-photo-next', false)
            ->assertSee('Resultado de la reparación')->assertDontSee('data-maintenance-save', false);
        $origin = '/notificaciones?category=repairs&status=read&page=2';
        $destination = route('notifications.repair', ['job' => $job, 'return_to' => $origin]);
        $this->post(route('notifications.open', $notification->id), ['return_to' => $origin])->assertRedirect($destination);
        $this->get($destination)->assertOk()->assertViewHas('returnUrl', $origin)->assertSee(e($origin), false);
        foreach (['https://example.com', '//example.com', '/%2fexample.com', '/%5cexample.com', ['invalid']] as $unsafeOrigin) {
            $this->post(route('notifications.open', $notification->id), ['return_to' => $unsafeOrigin])
                ->assertRedirect(route('notifications.repair', $job));
        }
        $this->admin->update(['permissions' => ['containers.view']]);
        $this->assertSame(0, $service->inbox($this->admin, $this->branch)->count());
        $this->post(route('notifications.read', $notification->id))->assertNotFound();
        $this->get(route('notifications.repair', $job))->assertForbidden();
    }

    public function test_general_audit_notifications_are_only_for_the_external_auditor_and_internal_users(): void
    {
        $job = $this->start();
        $service = app(SystemNotifications::class);
        $container = Contenedor::findOrFail($this->container);
        $service->auditAssigned($container, $this->branch);
        $this->assertSame(0, $service->inbox($this->mechanic, $this->branch)->where('n.job_id', $job)->count());
        $this->assertSame(0, $service->inbox($this->other, $this->branch)->count());
        $this->assertSame(1, $service->inbox($this->auditor, $this->branch)->count());
        $service->maintenanceResult($job, 'Reparación confirmada', 'Trabajo confirmado por el encargado.');
        $this->actingAs($this->mechanic)->get(route('notifications.index'))->assertOk()->assertSee('Reparación confirmada')->assertDontSee('Asignación de auditora actualizada');
        $notice = $service->inbox($this->mechanic, $this->branch)->where('n.job_id', $job)->first();
        $origin = '/mantenimiento/ordenes?patio_id=3&bloque_id=5&search=panel%20lateral';
        $destination = route('maintenance.show', ['job' => $job, 'step' => 2, 'return_to' => $origin]);
        $this->post(route('notifications.open', $notice->id), ['return_to' => $origin])->assertRedirect($destination);
        $this->get($destination)->assertOk()->assertViewHas('returnUrl', $origin);
        $this->actingAs($this->auditor)->get(route('notifications.index'))->assertOk()->assertSee('Contenedor asignado a tu auditora');
        $this->auditor->update(['external_audit_company_id' => null]);
        $this->get(route('notifications.index'))->assertForbidden();
    }

    public function test_notification_folders_do_not_delete_the_repair_and_filters_update_without_navigation(): void
    {
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $service = app(SystemNotifications::class);
        $id = (int) $service->inbox($this->admin, $this->branch)->where('n.job_id', $job)->value('n.id');
        Livewire::test(NotificationBell::class)->call('act', 'delete', $id)->assertStatus(403);
        $this->actingAs($this->admin);
        $inbox = Livewire::test(Inbox::class)
            ->set('search', 'sin-coincidencias-'.uniqid())->assertSee('Sin coincidencias')
            ->set('search', '')->set('category', 'repairs')->assertSee('Reparación pendiente de revisión');
        $inbox->set('selected', [(string) $id])->call('act', 'archive')->assertDispatched('notifications-changed');
        $this->assertFalse($service->inbox($this->admin, $this->branch)->where('n.id', $id)->exists());
        $expiry = DB::table('system_notifications')->where('id', $id)->value('expires_at');
        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), Carbon::parse($expiry)->toDateString());
        $this->assertTrue($service->inbox($this->admin, $this->branch, 'archived')->where('n.id', $id)->exists());
        $inbox->set('folder', 'archived')->assertSee('Reparación pendiente de revisión')->set('selected', [(string) $id])->call('act', 'unarchive');
        $this->assertTrue($service->inbox($this->admin, $this->branch)->where('n.id', $id)->exists());
        $this->assertDatabaseHas('system_notifications', ['id' => $id, 'archived_at' => null, 'expires_at' => null]);
        $service->pruneExpired();
        $this->assertDatabaseHas('system_notifications', ['id' => $id]);
        $service->act($this->admin, $this->branch, 'archive', [$id]);
        DB::table('system_notifications')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
        $service->pruneExpired();
        $this->assertDatabaseMissing('system_notifications', ['id' => $id]);
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $job, 'status' => 'pending_review']);
    }

    public function test_status_notifications_reach_internal_branch_users_but_not_mechanics(): void
    {
        $job = $this->start();
        $container = Contenedor::findOrFail($this->container);
        $before = (object) $container->getAttributes();
        $newStatus = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        $after = ['estatus_unidad_id' => $newStatus, 'estatus_box_id' => $container->estatus_box_id, 'estatus_ipsa_id' => $container->estatus_ipsa_id];
        $service = app(SystemNotifications::class);
        $service->containerStatuses($container, $this->branch, $before, $after, $this->admin);
        $internal = $service->inbox($this->admin, $this->branch)->where('n.category', 'containers')->first();
        $mechanic = $service->inbox($this->mechanic, $this->branch)->where('n.event_key', $internal->event_key)->first();
        $this->assertNull($mechanic);
        $this->assertStringContainsString($this->admin->username, $internal->body);
        $this->assertStringContainsString('OK QA', $internal->body);
        $this->assertNotNull($internal->created_at);
        $this->assertSame(0, $service->inbox($this->other, $this->branch)->where('n.event_key', $internal->event_key)->count());
        $this->assertSame(0, $service->inbox($this->auditor, $this->branch)->where('n.event_key', $internal->event_key)->count());
        $this->actingAs($this->admin)->post(route('notifications.open', $internal->id), ['return_to' => '/notificaciones?category=containers'])
            ->assertRedirect(route('notifications.detail', ['notification' => $internal->id, 'folder' => 'inbox', 'return_to' => '/notificaciones?category=containers']));
        $this->get(route('notifications.detail', $internal->id))->assertOk()->assertSee($this->admin->username)->assertSee('OK QA')->assertDontSee('data-maintenance-tab', false)
            ->assertViewHas('actorName', $this->admin->username)
            ->assertViewHas('containerName', $container->nombre)
            ->assertViewHas('changes', fn (array $changes): bool => count($changes) === 1 && $changes[0]['part'] === 'Unidad' && $changes[0]['after'] === 'OK QA')
            ->assertSee('Comparación de estados')->assertSee('Antes')->assertSee('Después');
        $this->actingAs($this->mechanic)->get(route('notifications.detail', $internal->id))->assertNotFound();
        $service->containerStatuses($container, $this->branch, $before, (array) $before, $this->admin);
        $this->assertSame(1, $service->inbox($this->admin, $this->branch)->where('n.category', 'containers')->count());
    }

    public function test_damaged_ingress_reaches_branch_mechanics_without_a_job_and_is_idempotent(): void
    {
        $service = app(SystemNotifications::class);
        $unitOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        $boxOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 2, 'estado_id' => 1]);
        $damage = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'DMG QA', 'tipo_estatus_id' => 2, 'estado_id' => 1]);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_unidad_id' => $unitOk, 'estatus_box_id' => $boxOk]);
        $container = Contenedor::findOrFail($this->container);
        $service->damagedIngress($container, $this->branch);
        $this->assertSame(0, $service->inbox($this->mechanic, $this->branch)->count());
        $foreignBranch = DB::table('sucursales')->insertGetId(['nombre' => 'QA Otra sucursal '.uniqid(), 'ubicacion' => 'Temporal', 'estado_id' => 1]);
        $this->admin->update(['rol_id' => 4, 'sucursal_id' => $foreignBranch]);
        $container->update(['estatus_box_id' => $damage, 'observaciones_box' => 'Panel lateral golpeado.']);
        $service->damagedIngress($container, $this->branch);
        $service->damagedIngress($container, $this->branch);
        $this->assertSame(1, $service->inbox($this->mechanic, $this->branch)->count());
        $this->assertSame(1, $service->inbox($this->other, $this->branch)->count());
        $this->assertSame(0, $service->inbox($this->admin, $foreignBranch)->count());
        $notice = $service->inbox($this->mechanic, $this->branch)->first();
        $this->assertNull($notice->job_id);
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->post(route('notifications.open', $notice->id))->assertRedirect(route('notifications.detail', ['notification' => $notice->id, 'folder' => 'inbox']));
        $this->get(route('notifications.detail', $notice->id))->assertOk()->assertSee('Panel lateral golpeado.');
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['closed_at' => now()]);
        $this->assertSame(0, $service->inbox($this->mechanic, $this->branch)->count());
    }

    public function test_healthy_ingress_notifies_internal_branch_users_but_not_the_mechanic(): void
    {
        $unitOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        $boxOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 2, 'estado_id' => 1]);
        $reference = Contenedor::findOrFail($this->container);
        $cycle = DB::table('container_ingress_cycles')->find($this->cycle);
        $block = Bloque::with('patio')->findOrFail($reference->bloque_id);
        $this->other->update(['rol_id' => 3, 'permissions' => ['containers.view']]);
        $data = ['posicion' => 2, 'tipo_unidad' => $reference->tipo_unidad_id, 'modelo' => $reference->modelo_id,
            'iso_code' => '22G1', 'marca' => 'QA', 'estado_unidad' => $unitOk, 'status_box' => $boxOk,
            'estatus_ipsa' => $this->oosStatus, 'fecha_ingreso' => now()->toDateString(),
            'transportista' => 'QA', 'placa_cabezal' => 'P123ABC', 'shipping_line_id' => $cycle->shipping_line_id, 'audit_company_id' => $cycle->audit_company_id];
        $result = app(IngresoService::class)->register($this->admin, $data, 'NTFU'.random_int(1000000, 9999999), $block);
        $container = Contenedor::findOrFail($result['container_id']);
        $service = app(SystemNotifications::class);
        $service->containerIngress($container, $this->branch, $this->admin);
        $key = 'container-ingress:'.$container->current_ingress_cycle_id;
        $notice = $service->inbox($this->admin, $this->branch)->where('n.event_key', $key)->first();
        $this->assertNotNull($notice);
        $this->assertSame(1, $service->inbox($this->admin, $this->branch)->where('n.event_key', $key)->count());
        $this->assertSame(1, $service->inbox($this->other, $this->branch)->where('n.event_key', $key)->count());
        $this->assertSame(0, $service->inbox($this->mechanic, $this->branch)->count());
        $this->assertSame(0, $service->inbox($this->auditor, $this->branch)->where('n.event_key', $key)->count());
        $this->assertStringContainsString($this->admin->username, $notice->body);
        $this->actingAs($this->admin)->post(route('notifications.open', $notice->id))
            ->assertRedirect(route('notifications.detail', ['notification' => $notice->id, 'folder' => 'inbox']));
        $this->get(route('notifications.detail', $notice->id))->assertOk()->assertSee('Estados al ingresar')->assertSee($container->nombre)->assertSee('Registrado por');
        $this->other->update(['permissions' => []]);
        $this->assertSame(0, $service->inbox($this->other, $this->branch)->where('n.event_key', $key)->count());
    }

    public function test_legacy_general_mechanic_notices_are_hidden_but_personal_results_remain_visible(): void
    {
        $job = $this->start();
        $base = ['user_id' => $this->mechanic->id, 'branch_id' => $this->branch, 'audience' => 'mechanic',
            'job_id' => $job, 'container_id' => $this->container, 'ingress_cycle_id' => $this->cycle, 'body' => 'Aviso anterior.', 'created_at' => now()];
        $hidden = DB::table('system_notifications')->insertGetId($base + ['event_key' => 'container-status:'.uniqid(), 'category' => 'repairs', 'title' => 'Estado del contenedor actualizado']);
        $visible = DB::table('system_notifications')->insertGetId($base + ['event_key' => 'maintenance-result:'.uniqid(), 'category' => 'repairs', 'title' => 'Reparación confirmada']);
        $service = app(SystemNotifications::class);
        $this->assertSame([$visible], $service->inbox($this->mechanic, $this->branch)->pluck('n.id')->map(fn ($id): int => (int) $id)->all());
        $this->post(route('notifications.open', $hidden))->assertNotFound();
        $service->maintenanceResult($job, 'La auditora solicitó una corrección', 'Corregir el sellado.', 'correction-requested');
        $this->assertSame(2, $service->inbox($this->mechanic, $this->branch)->count());
        $this->assertSame(0, $service->inbox($this->other, $this->branch)->count());
    }

    public function test_repair_status_action_disappears_when_technical_damage_is_resolved(): void
    {
        $damage = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'DMG QA', 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        $unitOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        $boxOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 2, 'estado_id' => 1]);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_unidad_id' => $damage, 'estatus_box_id' => $boxOk]);
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('notifications.repair', $job))->assertOk()
            ->assertViewHas('canUpdateTechnicalStates', true)->assertSee('Actualizar estados del contenedor');
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_unidad_id' => $unitOk]);
        $this->get(route('notifications.repair', $job))->assertOk()->assertViewHas('canUpdateTechnicalStates', true)
            ->assertSee('Confirma primero el trabajo realizado');
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->get(route('notifications.repair', $job))->assertOk()->assertViewHas('canUpdateTechnicalStates', false)
            ->assertDontSee('Actualizar estados del contenedor')->assertDontSee('Intervenciones');
    }

    public function test_confirmed_repair_keeps_status_action_until_ipsa_is_available_with_the_required_permission(): void
    {
        $unitOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        $boxOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 2, 'estado_id' => 1]);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_unidad_id' => $unitOk, 'estatus_box_id' => $boxOk]);
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $version = DB::table('maintenance_jobs')->where('id', $job)->value('version');
        $this->actingAs($this->admin)->get(route('notifications.repair', $job))->assertOk()
            ->assertSee('Confirma primero el trabajo realizado');
        $this->get(route('containers.edit', $this->container))->assertOk();
        $this->actingAs($this->admin)->post(route('notifications.confirm', $job), ['version' => $version])->assertSessionHasNoErrors();
        $this->get(route('notifications.repair', $job))->assertOk()->assertViewHas('canUpdateTechnicalStates', true)
            ->assertSee('Actualizar estados del contenedor');
        $this->admin->update(['permissions' => ['maintenance.view', 'containers.ipsa']]);
        $this->actingAs($this->admin->fresh())->get(route('notifications.repair', $job))->assertOk()->assertViewHas('canUpdateTechnicalStates', true);
        $this->admin->update(['permissions' => ['maintenance.view', 'containers.technical']]);
        $this->actingAs($this->admin->fresh())->get(route('notifications.repair', $job))->assertOk()->assertViewHas('canUpdateTechnicalStates', false);
        $this->admin->update(['permissions' => null]);
        $available = DB::table('estatus_ipsa')->where('estado_id', 1)->whereRaw("UPPER(TRIM(nombre)) = 'DISPONIBLE'")->value('id');
        $this->assertNotNull($available);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => $available]);
        $this->actingAs($this->admin->fresh())->get(route('notifications.repair', $job))->assertOk()->assertViewHas('canUpdateTechnicalStates', false);
    }

    public function test_repair_detail_confirms_without_opening_a_notification_and_keeps_only_unfinished_actions(): void
    {
        $unitOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        $boxOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 2, 'estado_id' => 1]);
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $version = (int) DB::table('maintenance_jobs')->where('id', $job)->value('version');
        $returnUrl = route('corrections.container-history', ['container' => $this->container, 'q' => 'QA', 'page' => 2], false);
        $detail = route('corrections.detail', ['job' => $job, 'return_to' => $returnUrl]);
        $this->actingAs($this->admin)->get($detail)->assertOk()
            ->assertViewHas('canConfirmWork', true)->assertViewHas('canUpdateTechnicalStates', true)
            ->assertSee(route('corrections.confirm', $job), false)->assertSee('Confirma primero el trabajo realizado')
            ->assertDontSee('aria-controls="repairInterventionsMenu"', false);
        $this->post(route('corrections.confirm', $job), ['version' => $version, 'return_to' => $returnUrl])
            ->assertSessionHasNoErrors()->assertRedirect(route('corrections.detail', ['job' => $job, 'step' => 2, 'return_to' => $returnUrl]));
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $job, 'status' => 'completed']);
        $this->get($detail)->assertOk()->assertViewHas('canConfirmWork', false)
            ->assertSee('Actualizar estados del contenedor')->assertDontSee('Confirmar trabajo realizado');
        $available = DB::table('estatus_ipsa')->where('estado_id', 1)->whereRaw("UPPER(TRIM(nombre)) = 'DISPONIBLE'")->value('id');
        $this->assertNotNull($available);
        DB::table('contenedores')->where('id', $this->container)->update([
            'estatus_unidad_id' => $unitOk, 'estatus_box_id' => $boxOk, 'estatus_ipsa_id' => $available,
        ]);
        $this->get($detail)->assertOk()->assertViewHas('canUpdateTechnicalStates', false)
            ->assertDontSee('aria-controls="repairReviewOptions"', false)
            ->assertDontSee('aria-controls="repairInterventionsMenu"', false);
        $round = DB::table('maintenance_corrections')->insertGetId([
            'job_id' => $job, 'number' => 1, 'requested_by' => $this->auditor->id, 'reason' => 'Revisar sellado',
            'work_performed' => 'Sellado corregido', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get($detail)->assertOk()->assertSee('aria-controls="repairInterventionsMenu"', false)
            ->assertDontSee('aria-controls="repairReviewOptions"', false);
        $this->get(route('corrections.detail', ['job' => $job, 'intervention' => $round]))->assertOk()
            ->assertViewHas('canConfirmWork', false)->assertViewHas('canUpdateTechnicalStates', false)
            ->assertDontSee('aria-controls="repairReviewOptions"', false);
    }

    public function test_repair_detail_does_not_grant_review_actions_to_container_readers_or_mechanics(): void
    {
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $version = (int) DB::table('maintenance_jobs')->where('id', $job)->value('version');
        $this->get(route('corrections.detail', $job))->assertOk()
            ->assertViewHas('canConfirmWork', false)->assertViewHas('canUpdateTechnicalStates', false);
        $this->post(route('corrections.confirm', $job), ['version' => $version])->assertForbidden();
        $this->admin->update(['permissions' => ['containers.view']]);
        $this->actingAs($this->admin->fresh())->get(route('corrections.detail', $job))->assertOk()
            ->assertViewHas('canConfirmWork', false)->assertViewHas('canUpdateTechnicalStates', false)
            ->assertDontSee('aria-controls="repairReviewOptions"', false);
        $this->post(route('corrections.confirm', $job), ['version' => $version])->assertForbidden();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $job, 'status' => 'pending_review', 'version' => $version]);
    }

    public function test_old_repair_cannot_be_managed_when_a_newer_job_exists_in_the_same_ingress(): void
    {
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $original = (array) DB::table('maintenance_jobs')->find($job);
        $newer = $original;
        unset($newer['id']);
        $newer['status'] = 'in_progress';
        DB::table('maintenance_jobs')->insert($newer);
        $this->actingAs($this->admin)->get(route('corrections.detail', $job))->assertOk()
            ->assertViewHas('canConfirmWork', false)->assertViewHas('canUpdateTechnicalStates', false)
            ->assertDontSee('aria-controls="repairReviewOptions"', false);
        $this->post(route('corrections.confirm', $job), ['version' => $original['version']])->assertStatus(409);
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $job, 'status' => 'pending_review']);
    }

    public function test_select_all_spans_notification_pages_and_does_not_include_new_arrivals(): void
    {
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $service = app(SystemNotifications::class);
        $notification = (array) $service->inbox($this->admin, $this->branch)->where('n.job_id', $job)->first();
        $excludedId = (int) $notification['id'];
        unset($notification['id']);
        for ($index = 0; $index < 16; $index++) {
            DB::table('system_notifications')->insert(array_replace($notification, ['event_key' => 'selection-fixture:'.uniqid()]));
        }
        $this->actingAs($this->admin);
        $component = Livewire::test(Inbox::class)->assertSee('Página 1 de 2')->assertSee('17 notificaciones')
            ->call('toggleAll')->assertSet('allSelected', true)->assertViewHas('selectedCount', 17)
            ->call('nextPage')->assertSet('allSelected', true)->assertViewHas('selectedCount', 17)
            ->call('toggleNotification', $excludedId)->assertViewHas('selectedCount', 16);
        $newId = DB::table('system_notifications')->insertGetId(array_replace($notification, ['event_key' => 'new-arrival:'.uniqid()]));
        $component->call('refreshNotifications')->assertViewHas('selectedCount', 16)->call('act', 'archive');
        $this->assertSame(16, $service->inbox($this->admin, $this->branch, 'archived')->count());
        $this->assertSame(2, $service->inbox($this->admin, $this->branch)->count());
        $this->assertDatabaseHas('system_notifications', ['id' => $excludedId, 'archived_at' => null]);
        $this->assertDatabaseHas('system_notifications', ['id' => $newId, 'archived_at' => null]);
        $auditId = DB::table('system_notifications')->insertGetId(array_replace($notification, [
            'event_key' => 'category-fixture:'.uniqid(), 'category' => 'audits', 'audience' => 'internal',
        ]));
        Livewire::test(Inbox::class)->set('category', 'repairs')->call('toggleAll')
            ->assertViewHas('selectedCount', 2)->call('act', 'archive');
        $this->assertDatabaseHas('system_notifications', ['id' => $auditId, 'archived_at' => null]);
        Livewire::test(Inbox::class)->set('category', 'audits')->call('toggleAll')
            ->assertViewHas('selectedCount', 1)->call('act', 'archive');
        $this->assertSame(0, $service->inbox($this->admin, $this->branch)->count());
    }

    public function test_material_upload_and_stale_version_validation(): void
    {
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['materials' => [['part_id' => $this->part, 'quantity' => 0]]]))->assertSessionHasErrors('materials.0.quantity');
        DB::table('repair_parts')->where('id', $this->part)->update(['status' => 2]);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload())->assertSessionHasErrors('materials');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['materials' => [], 'evidence_before' => [UploadedFile::fake()->create('script.svg', 5, 'image/svg+xml')]]))->assertSessionHasErrors('evidence_before.0');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['materials' => [], 'evidence_test' => [UploadedFile::fake()->create('grande.pdf', 2049, 'application/pdf')]]))->assertSessionHasErrors('evidence_test');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['materials' => []]))->assertSessionHasNoErrors();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['materials' => []]))->assertSessionHasErrors('work');
        $this->assertSame(2, (int) DB::table('maintenance_jobs')->where('id', $id)->value('version'));
    }

    public function test_confirmation_creates_a_separate_notice_and_preserves_the_original_request(): void
    {
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('final.jpg')]]))->assertSessionHasNoErrors();
        $version = (int) DB::table('maintenance_jobs')->where('id', $job)->value('version');
        $service = app(SystemNotifications::class);
        $originalNotice = $service->inbox($this->admin, $this->branch)->where('n.job_id', $job)->first();
        $this->travel(10)->minutes();
        $this->actingAs($this->admin)->post(route('notifications.confirm', $job), ['version' => $version])->assertSessionHasNoErrors();
        $this->get(route('notifications.repair', $job))->assertOk()->assertSee('Confirmada por')->assertSee($this->admin->username)
            ->assertDontSee('Pendiente de revisión');
        Livewire::test(Inbox::class)->set('category', 'repairs')->assertSee('Reparación confirmada')->assertSee($this->admin->username)
            ->assertSee('Reparación pendiente de revisión')->assertSee('Por: '.$this->mechanic->username)
            ->assertSee('Por: '.$this->admin->username)->assertSee('Se solicitó revisar la reparación del contenedor')
            ->assertSee('Se confirmó la reparación del contenedor');
        Livewire::test(NotificationBell::class)->assertSee('Reparación confirmada')->assertSee('Por: '.$this->admin->username)
            ->assertSee('Reparación pendiente de revisión')->assertSee('Por: '.$this->mechanic->username);
        $this->assertEquals($originalNotice, DB::table('system_notifications')->find($originalNotice->id));
        $confirmation = $service->inbox($this->admin, $this->branch)->where('n.event_key', 'like', 'review-confirmed:%')->first();
        $this->assertNotNull($confirmation);
        $this->assertNotSame($originalNotice->id, $confirmation->id);
        $this->assertNotSame($originalNotice->created_at, $confirmation->created_at);
        $this->assertSame($this->admin->username, json_decode($confirmation->details, true)['actor']);
        $this->assertNull($confirmation->read_at);
        $this->assertNull($confirmation->archived_at);
        $service->reviewConfirmed($job, $this->admin);
        $this->assertSame(2, $service->inbox($this->admin, $this->branch)->where('n.job_id', $job)->count());
        $this->post(route('notifications.open', $originalNotice->id))->assertRedirect(route('notifications.repair', $job));
        $this->post(route('notifications.open', $confirmation->id))->assertRedirect(route('notifications.repair', $job));
        $this->actingAs($this->mechanic);
        Livewire::test(Inbox::class)->set('category', 'repairs')->assertSee('Reparación confirmada')
            ->assertSee('Por: '.$this->admin->username)->assertSee('Se confirmó la reparación del contenedor');
    }

    public function test_notifications_display_guatemala_time_without_changing_the_stored_utc_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 16:31:00', 'UTC'));
        app(SystemNotifications::class)->activity('containers', 'Registro actualizado', 'Equipo horario', [], [$this->branch], $this->admin);
        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)->assertSee('22/10/2026 10:31')->assertDontSee('22/10/2026 16:31');
        Livewire::test(NotificationBell::class)->assertSee('22/10/2026 · 10:31');
        $this->assertSame('2026-10-22 16:31:00', DB::table('system_notifications')->where('user_id', $this->admin->id)
            ->where('branch_id', $this->branch)->value('created_at'));
        $this->travelTo(Carbon::parse('2026-10-23 02:15:00', 'UTC'));
        app(SystemNotifications::class)->activity('containers', 'Registro actualizado', 'Equipo nocturno', [], [$this->branch], $this->admin);
        Livewire::test(Inbox::class)->assertSee('22/10/2026 20:15');
        Livewire::test(NotificationBell::class)->assertSee('22/10/2026 · 20:15');
    }

    public function test_external_corrections_preserve_the_original_and_lock_each_submitted_round(): void
    {
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('original-final.jpg')]]))->assertSessionHasNoErrors();
        $original = DB::table('maintenance_jobs')->find($job);
        $originalMaterials = DB::table('maintenance_materials')->where('job_id', $job)->get()->toJson();
        $originalEvidence = DB::table('maintenance_evidence')->where('job_id', $job)->get()->toJson();
        $this->actingAs($this->admin)->post(route('notifications.confirm', $job), ['version' => $original->version])->assertSessionHasNoErrors();
        $confirmed = DB::table('maintenance_jobs')->find($job);
        $this->assertSame('completed', $confirmed->status);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->post(route('notifications.confirm', $job), ['version' => $original->version])->assertSessionHasErrors('work');
        $verdict = ['estatus_pyp' => 'Rechazado', 'informacion_adicional' => 'Corregir el sellado de la puerta.', 'maintenance_version' => $confirmed->version];
        $this->actingAs($this->auditor)->patch(route('pyp-audits.update', $this->container), $verdict)->assertSessionHasNoErrors();
        $round = DB::table('maintenance_corrections')->where('job_id', $job)->first();
        $this->assertSame('draft', $round->status);
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $job, 'status' => 'returned_for_repair']);
        $this->get(route('corrections.photo', ['job' => $job, 'round' => $round->id, 'photo' => 0]))->assertNotFound();
        $data = ['round_id' => $round->id, 'version' => 1, 'action' => 'submit', 'work_performed' => 'Se reemplazó y selló el empaque.'];
        $this->actingAs($this->other)->patch(route('corrections.update', $job), $data)->assertForbidden();
        $this->actingAs($this->mechanic)->patch(route('corrections.update', $job), $data)->assertSessionHasErrors('work');
        $this->patch(route('corrections.update', $job), $data + ['photos' => [UploadedFile::fake()->image('correccion.jpg')]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round->id, 'status' => 'submitted', 'version' => 2]);
        $this->patch(route('corrections.update', $job), $data)->assertStatus(409);
        $this->assertSame($original->diagnosis, DB::table('maintenance_jobs')->find($job)->diagnosis);
        $this->assertSame($original->work_performed, DB::table('maintenance_jobs')->find($job)->work_performed);
        $this->assertSame($originalMaterials, DB::table('maintenance_materials')->where('job_id', $job)->get()->toJson());
        $this->assertSame($originalEvidence, DB::table('maintenance_evidence')->where('job_id', $job)->get()->toJson());
        $this->actingAs($this->auditor)->patch(route('pyp-audits.update', $this->container), $verdict)->assertSessionHasErrors('pyp');
        $verdict['maintenance_version'] = DB::table('maintenance_jobs')->find($job)->version;
        $this->patch(route('pyp-audits.update', $this->container), $verdict)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round->id, 'status' => 'rejected', 'work_performed' => $data['work_performed']]);
        $this->assertDatabaseHas('maintenance_corrections', ['job_id' => $job, 'number' => 2, 'status' => 'draft']);
        $this->assertSame(1, DB::table('maintenance_jobs')->where('container_id', $this->container)->count());
    }

    public function test_internal_review_permissions_do_not_grant_access_to_the_mechanic_workspace(): void
    {
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['evidence_before' => [UploadedFile::fake()->image('privada.jpg')]]))->assertSessionHasNoErrors();
        $file = DB::table('maintenance_evidence')->where('job_id', $id)->first();
        $this->assertContains('maintenance.view', AccessPermissions::keys());
        $this->assertContains('maintenance.confirm', AccessPermissions::keys());
        $this->assertSame('Revisiones de reparaciones', AccessPermissions::modules()['maintenance'][0]);
        foreach ([1, 2, 3] as $role) {
            $this->admin->update(['rol_id' => $role, 'permissions' => null]);
            $this->actingAs($this->admin)->get('/')->assertOk()->assertDontSee(route('maintenance.orders'), false);
            $this->admin->update(['permissions' => ['maintenance.view', 'maintenance.confirm']]);
            foreach (['/mantenimiento/ordenes', '/mantenimiento/trabajos', '/mantenimiento/contenedores/'.$this->container, '/mantenimiento/ordenes.php', '/mantenimiento/ordenes/nueva',
                '/mantenimiento/ordenes/'.$id, '/mantenimiento/ordenes/'.$id.'/evidencias/'.$file->id] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Intento de acceso interno'])->assertForbidden();
            $this->patch('/mantenimiento/ordenes/'.$id, $this->payload())->assertForbidden();
            try {
                app(MaintenanceWorkflow::class)->authorize($this->admin, $this->branch);
                $this->fail('Un rol interno no debe acceder al flujo del mecanico.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_open_maintenance_blocks_external_verdict_and_dispatch_even_if_audit_is_approved(): void
    {
        $this->start();
        $this->actingAs($this->auditor)->patch('/contenedores/'.$this->container.'/pyp', [
            'estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'Intento antes de revision interna',
        ])->assertSessionHasErrors('pyp');
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Aprobado']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->actingAs($this->admin)->post('/despachos', ['contenedor_id' => $this->container])->assertSessionHasErrors('dispatch');
        $this->assertDatabaseHas('contenedores', ['id' => $this->container, 'estado_id' => 1]);
    }

    public function test_closed_cycle_cannot_receive_or_modify_work(): void
    {
        $id = $this->start();
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['closed_at' => now()]);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload())->assertStatus(409);
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Diagnostico de ingreso cerrado'])->assertSessionHasErrors('container_id');
    }

    public function test_private_evidence_and_jobs_do_not_cross_branch_or_company_boundaries(): void
    {
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['evidence_before' => [UploadedFile::fake()->image('antes.jpg')]]))->assertSessionHasNoErrors();
        $file = DB::table('maintenance_evidence')->where('job_id', $id)->first();
        $this->actingAs($this->auditor)->get('/mantenimiento/ordenes/'.$id.'/evidencias/'.$file->id)->assertForbidden();
        $otherBranch = DB::table('sucursales')->insertGetId(['nombre' => 'QA Otra sucursal '.uniqid(), 'ubicacion' => 'Temporal', 'estado_id' => 1]);
        $this->other->update(['sucursal_id' => $otherBranch]);
        $this->actingAs($this->other)->withSession(['sucursal_activa' => $otherBranch]);
        $this->get('/mantenimiento/ordenes/'.$id)->assertNotFound();
        $this->get('/mantenimiento/ordenes/'.$id.'/evidencias/'.$file->id)->assertNotFound();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload())->assertNotFound();
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Intento desde otra sucursal'])->assertNotFound();
        $this->withSession(['sucursal_activa' => $this->branch])->get('/mantenimiento/ordenes')->assertForbidden();
    }

    public function test_evidence_limit_and_historical_material_names_are_preserved(): void
    {
        $id = $this->start();
        $files = [];
        for ($index = 0; $index < 4; $index++) {
            $files[] = UploadedFile::fake()->image('antes-'.$index.'.jpg');
        }
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['evidence_before' => $files]))->assertSessionHasNoErrors();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2, 'evidence_before' => [UploadedFile::fake()->image('sexta.jpg')]]))->assertSessionHasErrors('evidence');
        $this->assertSame(5, DB::table('maintenance_evidence')->where('job_id', $id)->count());
        DB::table('repair_parts')->where('id', $this->part)->update(['name' => 'Nombre cambiado', 'unit' => 'kg', 'status' => 2]);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_materials', ['job_id' => $id, 'name' => 'Sellador industrial', 'unit' => 'tubo']);
    }

    public function test_mechanic_inventory_reuses_container_controls_filters_and_safe_3d_actions(): void
    {
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        $table = Livewire::test(InventoryTable::class, ['maintenanceMode' => true])
            ->assertSee('Mantenimiento')->assertSee('Vista 3D')->assertSee('Selecciona un patio')
            ->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertSee($record->nombre)->assertSee('Opciones')->assertSee('>Reparar</a>', false)->assertDontSee('Nuevo trabajo')
            ->assertDontSee('XXXXXXXXXXX')->assertDontSee('Asignar PYP')->assertSee('Auditora externa')
            ->assertDontSee(route('transfers.create'), false)->assertDontSee(route('dispatches.create'), false)
            ->assertDontSee('Aprobacion anterior')->assertDontSee('Auditora mantenimiento')->assertSee('Aprobado')
            ->assertDontSee(route('maintenance.jobs'), false)->assertSee('Por atender');
        $table->assertSee('id="maintenanceStatusFilter-options"', false)->assertSee('id="unitStatusFilter-options"', false)
            ->assertDontSee('wire:model.live="maintenanceStatusFilter"', false);
        $table->call('selectPerPage', 5)->assertSet('perPage', 5)
            ->call('setSort', 'nombre', 'asc')->assertSet('sort', 'nombre')
            ->set('nameFilter', 'NO-COINCIDE')->assertDontSee($record->nombre)
            ->call('clearFilters')->assertSee($record->nombre)
            ->call('setViewMode', 'yard3d')->assertSee('data-yard-scene', false)
            ->assertViewHas('yardScene', function (array $scene) use ($record): bool {
                $item = collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers'])->firstWhere('id', $this->container);

                return $scene['fit_to_viewport'] && $item['nombre'] === $record->nombre && $item['pyp'] === null
                    && str_contains($item['actions']['info'], '/mantenimiento/contenedores/')
                    && str_contains($item['actions']['maintenance'], '/mantenimiento/ordenes/nueva')
                    && ! isset($item['actions']['dispatch'], $item['actions']['transfer']);
            });
        $this->get('/mantenimiento/contenedores/'.$this->container)->assertOk()->assertSee($record->nombre)->assertDontSee('Aprobacion anterior');
        $id = $this->start();
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->call('selectPatio', $block->patio_id)
            ->call('selectBlock', $block->id)->assertSee(route('maintenance.show', $id), false)->assertSee('Continuar reparación')
            ->assertDontSee('Ver trabajo #')->assertDontSee('>Reparar</a>', false)
            ->assertViewHas('yardScene', function (array $scene): bool {
                $item = collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers'])->firstWhere('id', $this->container);

                return $item['actions']['maintenance_label'] === 'Continuar reparación';
            });
        $this->get('/mantenimiento/trabajos')->assertOk()->assertSee($record->nombre);
        $this->get('/mantenimiento/ordenes/nueva?container_id='.$this->container)->assertNotFound();
    }

    public function test_mechanic_information_reuses_container_layout_with_only_relevant_read_only_fields(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update([
            'iso_code' => '22G1', 'marca' => 'Marca tecnica QA', 'observaciones_unidad' => 'Golpe tecnico en panel lateral',
            'transportista' => 'Transportista privado QA', 'conductor' => 'Piloto privado QA',
            'placa_cabezal' => 'C123ABC', 'placa_chasis' => 'TC456DE',
        ]);
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['audit_reference' => 'Instruccion administrativa privada QA']);
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        $url = route('maintenance.container', ['container' => $this->container, 'patio_id' => $block->patio_id, 'bloque_id' => $block->id]);
        $response = $this->get($url)->assertOk()->assertViewIs('maintenance.container')->assertViewHas('mechanicMode', true)
            ->assertSee('container-info-shell')->assertSee('data-info-step="3"', false)->assertDontSee('data-info-step="4"', false)
            ->assertSee('Datos del equipo')->assertSee('Estados tecnicos')->assertSee('Auditoria')->assertSee('Ubicacion actual')
            ->assertSee('22G1')->assertSee('Marca tecnica QA')->assertSee('Golpe tecnico en panel lateral')
            ->assertSee('Auditora mantenimiento')->assertSee('Aprobado')->assertSee('Por atender')
            ->assertDontSee('Transportista privado QA')->assertDontSee('Piloto privado QA')->assertDontSee('C123ABC')->assertDontSee('TC456DE')
            ->assertDontSee('Instruccion administrativa privada QA')->assertDontSee('Aprobacion anterior')
            ->assertDontSee('Constancia ingreso')->assertDontSee('Modificar')->assertDontSee('Trasladar')->assertDontSee('Despachar')
            ->assertDontSee('>Reparar</a>', false)->assertSee('Volver')
            ->assertSee(route('maintenance.orders', ['patio_id' => $block->patio_id, 'bloque_id' => $block->id]));
        $response->assertViewHas('container', function (object $container): bool {
            return array_intersect(['transportista', 'conductor', 'placa_cabezal', 'placa_chasis', 'audit_reference', 'informacion_adicional'], array_keys(get_object_vars($container))) === [];
        });
        $this->start();
        $this->get($url)->assertOk()->assertSee('En reparacion')->assertDontSee('>Reparar</a>', false);
    }

    public function test_legacy_inventory_allows_oos_repair_without_cross_branch_access(): void
    {
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        DB::table('contenedores')->where('id', $this->container)->update(['current_ingress_cycle_id' => null]);
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->call('selectPatio', $block->patio_id)
            ->call('selectBlock', $block->id)->assertSee($record->nombre)->assertDontSee('Ingreso pendiente de regularizacion')
            ->assertSee('>Reparar</a>', false);
        $this->get('/mantenimiento/contenedores/'.$this->container)->assertOk()->assertSee('Faltan datos del ingreso')->assertDontSee('>Reparar</a>', false);
        $otherBranch = DB::table('sucursales')->insertGetId(['nombre' => 'QA Inventario ajeno '.uniqid(), 'ubicacion' => 'Temporal', 'estado_id' => 1]);
        $yard = DB::table('patios')->insertGetId(['nombre' => 'Patio ajeno', 'sucursal_id' => $otherBranch, 'estado_id' => 1]);
        $otherBlock = DB::table('bloques')->insertGetId(['numero' => 999, 'patio_id' => $yard, 'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1]);
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->call('selectPatio', $yard)
            ->call('selectBlock', $otherBlock)->assertViewHas('selectedPatio', null)->assertViewHas('containers', []);
        $this->withSession(['sucursal_activa' => $otherBranch])->get('/mantenimiento/ordenes')->assertForbidden();
        $this->withSession(['sucursal_activa' => $this->branch]);
        $this->actingAs($this->admin);
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->assertForbidden();
    }

    public function test_only_active_oos_containers_can_start_repair_and_audit_is_unchanged_when_rejected(): void
    {
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Diagnostico no autorizado'])->assertSessionHasErrors('container_id');
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Aprobado']);
        $this->get('/mantenimiento/ordenes/nueva')->assertRedirect(route('maintenance.orders'));
        $this->get('/mantenimiento/contenedores/'.$this->container)->assertOk()->assertDontSee('>Iniciar trabajo</a>', false);
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertSee($record->nombre)->assertDontSee('Fuera de OOS')->assertDontSee('Regularizar ingreso')->assertDontSee('>Reparar</a>', false)
            ->assertViewHas('containers', fn (array $containers): bool => $containers[0]->maintenance_status === null && ! $containers[0]->maintenance_can_start)
            ->assertViewHas('yardScene', function (array $scene): bool {
                $item = collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers'])->firstWhere('id', $this->container);

                return $item['actions']['maintenance'] === null;
            });
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => $this->oosStatus]);
        DB::table('estatus_ipsa')->where('id', $this->oosStatus)->update(['estado_id' => 2]);
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Estado OOS desactivado'])->assertSessionHasErrors('container_id');
    }

    public function test_repair_becomes_read_only_when_container_leaves_oos_and_cannot_submit(): void
    {
        $id = $this->start();
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        foreach (['save', 'submit'] as $action) {
            $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => $action]))->assertSessionHasErrors('work');
        }
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'version' => 1, 'status' => 'in_progress']);
        $this->assertDatabaseMissing('maintenance_materials', ['job_id' => $id]);
        $this->assertDatabaseMissing('maintenance_review_requests', ['job_id' => $id]);
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertSee('Solo lectura: el contenedor no está habilitado para reparación')->assertDontSee('name="action"', false);
    }

    public function test_work_column_and_external_audit_filters_remain_independent(): void
    {
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        $table = Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->set('maintenanceStatusFilter', 'Por atender')->assertSee($record->nombre)
            ->set('maintenanceStatusFilter', 'En reparacion')->assertDontSee($record->nombre)
            ->call('clearFilters')->set('pypStatusFilter', 'Aprobado')->assertSee($record->nombre)
            ->set('pypStatusFilter', 'Rechazado')->assertDontSee($record->nombre)->call('clearFilters');
        $id = $this->start();
        $table->set('maintenanceStatusFilter', 'En reparacion')->call('setSort', 'mantenimiento', 'asc')
            ->assertSee($record->nombre)->assertSee('Continuar reparación')->assertDontSee('Ver trabajo #'.$id)
            ->assertDontSee('Auditora mantenimiento')->assertSee('Pendiente de revision')->assertDontSee('Aprobacion anterior');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$table->html());
        $cells = (new \DOMXPath($document))->query('//td[@data-label="Mantenimiento"]');
        $this->assertCount(1, $cells);
        $this->assertSame('En reparacion', trim($cells->item(0)->textContent));
        $this->assertSame(0, $cells->item(0)->getElementsByTagName('a')->length);
    }

    public function test_mechanic_work_states_do_not_expose_eligibility_as_a_state(): void
    {
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        $table = Livewire::test(InventoryTable::class, ['maintenanceMode' => true])
            ->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertSee('Por atender')->assertSee('En reparacion')->assertSee('Reparado')
            ->assertDontSee('Regularizar ingreso')->assertDontSee('Fuera de OOS')->assertDontSee('Revisado')
            ->set('maintenanceStatusFilter', 'Por atender')->assertSee($record->nombre);
        $id = $this->start();
        $table->set('maintenanceStatusFilter', 'En reparacion')->assertSee($record->nombre)
            ->set('maintenanceStatusFilter', 'Reparado')->assertDontSee($record->nombre);
        foreach (['pending_review', 'completed'] as $status) {
            DB::table('maintenance_jobs')->where('id', $id)->update(['status' => $status]);
            $table->call('$refresh')->assertSee($record->nombre)
                ->assertViewHas('containers', fn (array $containers): bool => $containers[0]->maintenance_status === 'Reparado')
                ->assertSee('Reparaciones')->assertDontSee('Ver reparación')->assertDontSee('Continuar reparación')
                ->assertViewHas('containers', fn (array $containers): bool => $containers[0]->maintenance_actions['maintenance'] === null
                    && str_contains($containers[0]->maintenance_actions['history'], '/reparaciones/'.$id.'/historial'))
                ->assertDontSee('>Reparar</a>', false);
            $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertViewHas('job', fn (object $job): bool => $job->status === $status);
        }
        DB::table('contenedores')->where('id', $this->container)->update(['current_ingress_cycle_id' => null]);
        $table->set('maintenanceStatusFilter', 'Por atender')->assertSee($record->nombre)
            ->assertViewHas('containers', fn (array $containers): bool => (bool) $containers[0]->maintenance_can_start)
            ->assertDontSee('Regularizar ingreso')->assertSee('>Reparar</a>', false);
    }

    public function test_mechanic_menu_separates_corrections_and_history_and_hides_other_owners_actions(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'returned_for_repair']);
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        $table = Livewire::test(InventoryTable::class, ['maintenanceMode' => true])
            ->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertSee('Atender corrección')->assertSee('Reparaciones')->assertDontSee('Ver reparación')
            ->assertViewHas('containers', fn (array $rows): bool => str_contains($rows[0]->maintenance_actions['maintenance'], '/reparaciones/'.$id.'/correcciones'));
        $table->call('setViewMode', 'yard3d')->assertViewHas('yardScene', function (array $scene) use ($id): bool {
            $item = collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers'])->firstWhere('id', $this->container);

            return $item['actions']['maintenance_label'] === 'Atender corrección'
                && str_contains($item['actions']['history'], '/reparaciones/'.$id.'/historial');
        });
        DB::table('maintenance_jobs')->where('id', $id)->update(['mechanic_id' => $this->other->id]);
        $table->call('setViewMode', 'table')->assertDontSee('Atender corrección')->assertDontSee('>Reparaciones</a>', false)
            ->assertViewHas('containers', fn (array $rows): bool => $rows[0]->maintenance_actions['maintenance'] === null
                && $rows[0]->maintenance_actions['history'] === null);
    }

    public function test_legacy_repair_preserves_evidence_and_links_to_the_regularized_ingress(): void
    {
        $assignment = DB::table('container_ingress_cycles')->find($this->cycle);
        DB::table('contenedores')->where('id', $this->container)->update(['current_ingress_cycle_id' => null]);
        $this->get('/mantenimiento/ordenes/nueva?container_id='.$this->container)->assertOk()->assertSee('name="container_id" value="'.$this->container.'"', false);
        $id = $this->start();
        $record = DB::table('contenedores')->find($this->container);
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'ingress_cycle_id' => null, 'legacy_ingress_at' => $record->fecha_ingreso]);
        $this->assertNull($record->current_ingress_cycle_id);
        $this->actingAs($this->auditor)->patch('/contenedores/'.$this->container.'/pyp', [
            'estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'No aprobar durante una reparacion antigua',
        ])->assertSessionHasErrors('pyp');
        $this->actingAs($this->mechanic);
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Intento de duplicado antiguo'])->assertSessionHasErrors('container_id');
        $this->get('/mantenimiento/ordenes/nueva')->assertRedirect(route('maintenance.orders'));
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['evidence_before' => [UploadedFile::fake()->image('antes-legacy.jpg')]]))->assertSessionHasNoErrors();
        $file = DB::table('maintenance_evidence')->where('job_id', $id)->first();
        $this->get('/mantenimiento/contenedores/'.$this->container)->assertOk()->assertSee('En reparacion');
        $block = DB::table('bloques')->find($record->bloque_id);
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertSee('Continuar reparación')->assertViewHas('containers', fn (array $rows): bool => count($rows) === 1 && $rows[0]->maintenance_status === 'En reparacion');

        app(ContainerAuditAssignment::class)->change($this->admin, Contenedor::findOrFail($this->container), $this->branch, [
            'shipping_line_id' => $assignment->shipping_line_id, 'audit_company_id' => $assignment->audit_company_id,
            'assignment_reason' => 'Completar el ingreso antiguo sin perder su reparacion.',
        ]);
        $cycle = (int) DB::table('contenedores')->where('id', $this->container)->value('current_ingress_cycle_id');
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'ingress_cycle_id' => $cycle, 'version' => 3]);
        $this->assertTrue(MaintenanceWorkflow::hasOpenWork($cycle));
        $event = DB::table('maintenance_events')->where('job_id', $id)->where('action', 'ingress_linked')->first();
        $this->assertNull(json_decode($event->before_data, true)['job']['ingress_cycle_id']);
        $this->assertSame($cycle, json_decode($event->after_data, true)['job']['ingress_cycle_id']);
        Storage::disk('local')->assertExists($file->path);
        $this->assertDatabaseHas('maintenance_materials', ['job_id' => $id, 'part_id' => $this->part]);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2]))->assertSessionHasErrors('work');
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 3, 'action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('despues.jpg')]]))->assertSessionHasNoErrors();
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertViewHas('containers', fn (array $rows): bool => count($rows) === 1 && $rows[0]->maintenance_status === 'Reparado');
    }

    public function test_legacy_jobs_do_not_carry_over_to_a_different_ingress_date(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update(['current_ingress_cycle_id' => null]);
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('despues.jpg')]]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_review_requests', ['job_id' => $id, 'status' => 'pending']);
        DB::table('contenedores')->where('id', $this->container)->update(['fecha_ingreso' => now()->addDay()]);
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2]))->assertSessionHasErrors('work');
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertViewHas('containers', fn (array $rows): bool => $rows[0]->maintenance_job_id === null && (bool) $rows[0]->maintenance_can_start);
        $this->get('/mantenimiento/ordenes/nueva?container_id='.$this->container)->assertOk()->assertSee($record->nombre);
    }

    public function test_ingress_without_an_audit_company_can_still_be_repaired(): void
    {
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['audit_company_id' => null, 'audit_company_name' => null]);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['external_audit_company_id' => null, 'estado_id' => 2]);
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('despues.jpg')]]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'ingress_cycle_id' => $this->cycle, 'status' => 'pending_review']);
        $this->assertDatabaseHas('container_ingress_cycles', ['id' => $this->cycle, 'audit_company_id' => null]);
    }

    public function test_returned_work_is_distinct_from_repaired_and_keeps_the_ingress_blocked(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'returned_for_repair']);
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        $this->assertTrue(MaintenanceWorkflow::hasOpenWork($this->cycle));
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->set('maintenanceStatusFilter', 'Devuelto a reparacion')->assertSee($record->nombre)
            ->assertViewHas('containers', fn (array $containers): bool => $containers[0]->maintenance_status === 'Devuelto a reparacion')
            ->assertSee(route('maintenance.show', $id), false)
            ->set('maintenanceStatusFilter', 'Reparado')->assertDontSee($record->nombre);
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertViewHas('job', fn (object $job): bool => $job->status === 'returned_for_repair');
        $this->get('/mantenimiento/ordenes/nueva')->assertRedirect(route('maintenance.orders'));
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Intento de duplicar trabajo devuelto'])->assertSessionHasErrors('container_id');
        $this->actingAs($this->auditor)->patch('/contenedores/'.$this->container.'/pyp', [
            'estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'No debe aprobarse con trabajo devuelto',
        ])->assertSessionHasErrors('pyp');
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Aprobado']);
        $this->actingAs($this->admin)->post('/despachos', ['contenedor_id' => $this->container])->assertSessionHasErrors('dispatch');
    }

    public function test_mechanic_inventory_mode_cannot_be_changed_by_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->set('maintenanceMode', false);
    }

    public function test_mechanic_filters_work_through_the_real_livewire_endpoint(): void
    {
        $record = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($record->bloque_id);
        $component = Livewire::test(InventoryTable::class, ['maintenanceMode' => true])
            ->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id);
        $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => json_encode($component->snapshot), 'updates' => ['search' => $record->nombre],
            'calls' => [['path' => '', 'method' => 'selectPerPage', 'params' => [5]]],
        ]]], ['X-Livewire' => 'true', 'Referer' => route('maintenance.orders')])
            ->assertOk()->assertJsonPath('components.0.effects.html', fn (string $html): bool => str_contains($html, $record->nombre));
        $this->get('/usuarios', ['X-Livewire' => 'true'])->assertForbidden();
        $this->get('/mantenimiento/ordenes/nueva?container_id='.$this->container)
            ->assertOk()->assertSee('name="container_id" value="'.$this->container.'"', false);
    }
}
