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
use App\Services\MaintenanceCost;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $this->useIsolatedMysql();
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

    private function technicalStatus(string $name, int $type): int
    {
        $query = DB::table('estatus_contenedores')->where('nombre', $name)->where('tipo_estatus_id', $type);

        return (int) ($query->value('id') ?? DB::table('estatus_contenedores')->insertGetId([
            'nombre' => $name, 'tipo_estatus_id' => $type, 'estado_id' => 1,
        ]));
    }

    public function test_container_history_includes_dispatched_and_legacy_records_with_literal_search_and_pagination(): void
    {
        $this->actingAs($this->admin);
        $url = route('containers.history.index');
        $name = DB::table('contenedores')->where('id', $this->container)->value('nombre');
        $this->get($url)->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->total() === 1 && $rows->first()->history_state === 'active');
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['closed_at' => now()]);
        DB::table('contenedores')->where('id', $this->container)->update(['estado_id' => 2]);
        $copy = (array) DB::table('contenedores')->find($this->container);
        unset($copy['id']);
        $copy['current_ingress_cycle_id'] = null;
        for ($index = 0; $index < 11; $index++) {
            DB::table('contenedores')->insert(array_replace($copy, ['nombre' => 'HSTU'.str_pad((string) $index, 7, '0', STR_PAD_LEFT)]));
        }
        $this->get($url)->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->total() === 12 && $rows->count() === 10);
        $this->get($url.'?page=2')->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->count() === 2);
        $this->get($url.'?state=dispatched')->assertOk()->assertSee($name)->assertViewHas('containers', fn ($rows): bool => $rows->total() === 1);
        $this->get($url.'?state=active')->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->total() === 0);
        $this->get($url.'?state=legacy')->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->total() === 11);
        $legacyId = DB::table('contenedores')->where('nombre', 'HSTU0000000')->value('id');
        $this->get(route('containers.history.show', $legacyId))->assertOk()->assertViewHas('timeline', fn ($timeline): bool => $timeline->isEmpty())
            ->assertSee('Este registro antiguo no tiene un ciclo de ingreso identificado.');
        $this->get($url.'?search=%25')->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->total() === 0);
        $this->getJson($url.'?per_page=100000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson($url.'?state=unknown')->assertUnprocessable()->assertJsonValidationErrors('state');
    }

    public function test_container_history_keeps_cycles_repairs_audit_evidence_and_dispatch_documents_separate_after_reentry(): void
    {
        $job = $this->start();
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'completed']);
        $event = ['container_id' => $this->container, 'ingress_cycle_id' => $this->cycle, 'user_id' => $this->admin->id,
            'reason' => 'Registro anterior', 'created_at' => now()];
        Storage::disk('local')->put('audit-history/evidence.png', 'audit evidence');
        $audit = DB::table('container_assignment_events')->insertGetId($event + ['event_type' => 'external_audit_status',
            'after_data' => json_encode(['audit' => ['estatus_pyp' => 'Rechazado', 'informacion_adicional' => '<script>old audit</script>',
                'photos' => [['path' => 'audit-history/evidence.png', 'name' => 'evidence.png', 'mime' => 'image/png']]]])]);
        $dispatch = DB::table('container_assignment_events')->insertGetId($event + ['event_type' => 'dispatch',
            'after_data' => json_encode(['document' => ['numero' => 'EIR-TEST-OLD']])]);
        DB::table('container_assignment_events')->insert($event + ['event_type' => 'ingress',
            'after_data' => json_encode(['cycle' => ['shipping_line_name' => 'Naviera inicial inmutable', 'audit_company_name' => 'Auditora inicial inmutable']])]);
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['closed_at' => now(), 'shipping_line_name' => 'Naviera reasignada']);
        $copy = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        unset($copy['id']);
        $copy['closed_at'] = null;
        $copy['entered_at'] = now('America/Guatemala')->addDay()->format('Y-m-d H:i:s');
        $copy['created_at'] = now()->addDay();
        $next = DB::table('container_ingress_cycles')->insertGetId($copy);
        DB::table('contenedores')->where('id', $this->container)->update(['current_ingress_cycle_id' => $next]);
        $this->actingAs($this->admin);
        $url = route('containers.history.show', $this->container);
        $before = DB::table('container_assignment_events')->where('container_id', $this->container)->count();
        $this->get($url)->assertOk()->assertViewHas('cycleCount', 2)
            ->assertViewHas('timeline', fn ($timeline): bool => $timeline->pluck('cycle_id')->unique()->values()->all() === [$this->cycle])
            ->assertSee('Primer ingreso')->assertSee('Primer reingreso')->assertSee('<h3>Ingreso</h3>', false)->assertDontSee('<h3>Reingreso</h3>', false);
        $this->get($url.'?cycle='.$next)->assertOk()->assertSee('<h3>Reingreso</h3>', false)->assertDontSee('old audit');
        $this->get($url.'?cycle='.$this->cycle)->assertOk()->assertSee('Naviera inicial inmutable')->assertDontSee('Naviera reasignada')
            ->assertSee('&lt;script&gt;old audit&lt;/script&gt;', false)->assertDontSee('<script>old audit</script>', false)
            ->assertSee(route('documents.dispatch.ticket', ['event_id' => $dispatch]), false)
            ->assertViewHas('timeline', fn ($timeline): bool => $timeline->where('kind', 'repair')->count() === 1 && (int) $timeline->firstWhere('kind', 'repair')->job_id === $job);
        $photoUrl = route('containers.history.photo', ['container' => $this->container, 'event' => $audit, 'photo' => 0]);
        $this->get($photoUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($photoUrl.'9')->assertNotFound();
        $this->get(route('corrections.detail', $job))->assertOk()->assertViewHas('canConfirmWork', false)->assertViewHas('canUpdateTechnicalStates', false);
        $this->assertSame($before, DB::table('container_assignment_events')->where('container_id', $this->container)->count());
        $this->assertDatabaseHas('container_ingress_cycles', ['id' => $this->cycle, 'shipping_line_name' => 'Naviera reasignada']);
    }

    public function test_container_history_enforces_branch_role_and_container_view_permissions(): void
    {
        $url = route('containers.history.show', $this->container);
        $foreignBranch = DB::table('sucursales')->insertGetId(['nombre' => 'Historial privado '.uniqid(), 'estado_id' => 1]);
        $copy = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        unset($copy['id']);
        $copy['branch_id'] = $foreignBranch;
        $copy['shipping_line_name'] = 'Naviera privada fuera de sucursal';
        $foreignCycle = DB::table('container_ingress_cycles')->insertGetId($copy);
        $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSee('Naviera privada fuera de sucursal');
        $this->get($url.'?cycle='.$foreignCycle)->assertNotFound();
        $this->withSession(['sucursal_activa' => $foreignBranch])->get($url)->assertForbidden();
        $this->withSession(['sucursal_activa' => $this->branch]);
        $this->admin->update(['rol_id' => 3, 'permissions' => ['containers.view']]);
        $this->actingAs($this->admin->fresh())->get($url)->assertOk();
        $this->get(route('containers.history.index'))->assertOk();
        $this->admin->update(['rol_id' => 1, 'permissions' => null]);
        $this->actingAs($this->admin->fresh())->withSession(['sucursal_activa' => $foreignBranch])->get($url.'?cycle='.$foreignCycle)
            ->assertOk()->assertSee('Naviera privada fuera de sucursal');
        $this->withSession(['sucursal_activa' => $this->branch]);
        $this->admin->update(['rol_id' => 3]);
        $this->admin->update(['permissions' => ['reports.view']]);
        $this->actingAs($this->admin->fresh())->get($url)->assertForbidden();
        $this->actingAs($this->auditor)->get($url)->assertForbidden();
        $this->actingAs($this->mechanic)->get($url)->assertForbidden();
    }

    public function test_container_history_keeps_archived_transfers_visible_and_audit_photos_private_to_their_branch(): void
    {
        $block = DB::table('contenedores')->where('id', $this->container)->value('bloque_id');
        $foreignBranch = DB::table('sucursales')->insertGetId(['nombre' => 'Sucursal de evidencia '.uniqid(), 'estado_id' => 1]);
        $yard = DB::table('patios')->insertGetId(['nombre' => 'Patio historico privado', 'sucursal_id' => $foreignBranch, 'estado_id' => 1]);
        $foreignBlock = DB::table('bloques')->insertGetId(['numero' => 1, 'patio_id' => $yard, 'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1]);
        $operation = DB::table('tipos_operacion')->where('nombre', 'TRASLADO')->value('id');
        foreach ([$block, $foreignBlock] as $origin) {
            $event = DB::table('logs_eventos')->insertGetId(['contenedor_id' => $this->container, 'usuario_id' => $this->admin->id,
                'tipo_operacion_id' => $operation, 'observaciones' => 'Traslado historico', 'estado_id' => 2]);
            DB::table('logs_traslados')->insert(['evento_id' => $event, 'bloque_anterior_id' => $origin,
                'posicion_anterior' => 1, 'bloque_nuevo_id' => $origin, 'posicion_nueva' => 2]);
        }
        $copy = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        unset($copy['id']);
        $copy['branch_id'] = $foreignBranch;
        $copy['entered_at'] = now('America/Guatemala')->addDay()->format('Y-m-d H:i:s');
        $copy['created_at'] = now()->addDay();
        $cycle = DB::table('container_ingress_cycles')->insertGetId($copy);
        Storage::disk('local')->put('audit-history/private.png', 'private evidence');
        $audit = DB::table('container_assignment_events')->insertGetId(['container_id' => $this->container, 'ingress_cycle_id' => $cycle,
            'user_id' => $this->admin->id, 'event_type' => 'external_audit_status', 'reason' => 'Auditoria privada', 'created_at' => now(),
            'after_data' => json_encode(['audit' => ['photos' => [['name' => 'private.png', 'mime' => 'image/png', 'path' => 'audit-history/private.png']]]])]);
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['closed_at' => now()]);
        DB::table('contenedores')->where('id', $this->container)->update(['estado_id' => 2]);
        $this->actingAs($this->admin)->get(route('containers.history.show', $this->container))->assertOk()
            ->assertViewHas('timeline', fn ($timeline): bool => $timeline->where('kind', 'transfer')->count() === 1)
            ->assertDontSee('Patio historico privado');
        $this->get(route('containers.history.photo', ['container' => $this->container, 'event' => $audit, 'photo' => 0]))->assertNotFound();
        $this->get(route('containers.history.show', ['container' => $this->container, 'cycle' => $cycle]))->assertNotFound();
        $this->get(route('containers.history.show', $this->container + 10000000))->assertNotFound();
    }

    public function test_container_history_filters_dates_sorts_columns_and_preserves_live_query_inputs(): void
    {
        $this->actingAs($this->admin);
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update([
            'entered_at' => '2026-10-01 08:00:00', 'closed_at' => '2026-10-03 04:30:00', 'shipping_line_name' => 'Naviera inicial',
        ]);
        $copy = (array) DB::table('contenedores')->find($this->container);
        unset($copy['id']);
        $copy['nombre'] = 'AAAA0000001';
        $second = DB::table('contenedores')->insertGetId($copy);
        $cycle = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        unset($cycle['id']);
        $cycle['container_id'] = $second;
        $cycle['entered_at'] = '2026-10-05 08:00:00';
        $cycle['closed_at'] = null;
        $cycle['shipping_line_name'] = 'ZZZ naviera';
        $secondCycle = DB::table('container_ingress_cycles')->insertGetId($cycle);
        DB::table('contenedores')->where('id', $second)->update(['current_ingress_cycle_id' => $secondCycle]);
        $url = route('containers.history.index');
        $this->get($url.'?sort=name&order=asc')->assertOk()
            ->assertViewHas('containers', fn ($rows): bool => (int) $rows->first()->id === $second)
            ->assertDontSee('>Consultar<', false)->assertSee('data-admin-live-query', false)
            ->assertSeeInOrder(['>Contenedor<', 'Naviera del ingreso', '<th>Estado del contenedor</th>', 'Reingresos', 'Ultimo ingreso', 'Ultima salida'], false)
            ->assertDontSee('data-history-help', false);
        $this->get($url.'?sort=name&order=desc')->assertOk()
            ->assertViewHas('containers', fn ($rows): bool => (int) $rows->first()->id === $this->container);
        $this->get($url.'?shipping_line=ZZZ&sort=shipping_line&order=asc')->assertOk()
            ->assertViewHas('containers', fn ($rows): bool => $rows->total() === 1 && (int) $rows->first()->id === $second);
        $this->get($url.'?ingress_from=2026-10-01&ingress_to=2026-10-01')->assertOk()
            ->assertViewHas('containers', fn ($rows): bool => $rows->total() === 1 && (int) $rows->first()->id === $this->container);
        $this->get($url.'?ingress_to=2026-10-01')->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->total() === 1);
        $this->get($url.'?exit_from=2026-10-02&exit_to=2026-10-02')->assertOk()
            ->assertViewHas('containers', fn ($rows): bool => $rows->total() === 1);
        $this->get($url.'?exit_from=2026-10-03')->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->total() === 0);
        $this->get($url.'?reentries=0&sort=reentries&order=desc')->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->total() === 2);
        $this->get($url.'?reentries=1')->assertOk()->assertViewHas('containers', fn ($rows): bool => $rows->total() === 0);
        $this->getJson($url.'?ingress_from=2026-10-05&ingress_to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('ingress_to');
        $this->getJson($url.'?sort=private_column')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson($url.'?order=invalid')->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    public function test_container_history_returns_to_the_exact_list_filters_and_rejects_external_return_urls(): void
    {
        $this->actingAs($this->admin);
        $return = '/historial-contenedores?search=MNTU&state=all&per_page=25&sort=name&order=asc&page=2';
        $this->get(route('containers.history.show', ['container' => $this->container, 'return_to' => $return]))
            ->assertOk()->assertViewHas('returnUrl', $return)->assertSee('href="'.htmlspecialchars($return, ENT_QUOTES).'"', false);
        foreach (['https://example.com/historial-contenedores', '//example.com/historial-contenedores', 'https:/historial-contenedores', '/contenedores', '/historial-contenedores\\evil'] as $unsafe) {
            $this->get(route('containers.history.show', ['container' => $this->container, 'return_to' => $unsafe]))
                ->assertOk()->assertViewHas('returnUrl', '/historial-contenedores');
        }
        $this->get(route('containers.history.index', ['state' => 'active', 'sort' => 'name', 'order' => 'asc']))
            ->assertOk()->assertSee('return_to=%2Fhistorial-contenedores%3Fstate%3Dactive%26sort%3Dname%26order%3Dasc', false);
    }

    public function test_container_history_selects_one_complete_visit_without_pagination_and_counts_only_repeat_ingresses(): void
    {
        $this->actingAs($this->admin);
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['entered_at' => '2026-01-01 08:00:00', 'closed_at' => '2026-01-02 18:00:00']);
        $copy = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        unset($copy['id']);
        $last = $this->cycle;
        for ($index = 1; $index < 16; $index++) {
            $date = Carbon::parse('2026-01-01')->addDays($index * 2);
            $last = DB::table('container_ingress_cycles')->insertGetId(array_replace($copy, [
                'entered_at' => $date->format('Y-m-d').' 08:00:00', 'closed_at' => $date->addDay()->format('Y-m-d').' 18:00:00',
            ]));
        }
        DB::table('contenedores')->where('id', $this->container)->update(['current_ingress_cycle_id' => $last]);
        for ($index = 0; $index < 30; $index++) {
            DB::table('container_assignment_events')->insert(['container_id' => $this->container, 'ingress_cycle_id' => $this->cycle,
                'user_id' => $this->admin->id, 'event_type' => 'instruction', 'reason' => 'Instruccion del primer ingreso '.$index,
                'created_at' => Carbon::parse('2026-01-01 15:00:00')->addMinutes($index), 'after_data' => '{}']);
        }
        $url = route('containers.history.show', $this->container);
        $this->get($url)->assertOk()->assertViewHas('cycleCount', 16)
            ->assertViewHas('timeline', fn ($timeline): bool => $timeline->count() === 32
                && $timeline->first()->kind === 'ingress' && (int) $timeline->first()->cycle_id === $this->cycle
                && $timeline->last()->kind === 'dispatch' && $timeline->pluck('cycle_id')->unique()->all() === [$this->cycle])
            ->assertSee('history-timeline', false)->assertDontSee('history-stays', false)->assertDontSee('history-jobs', false);
        $this->get($url.'?cycle='.$last)->assertOk()->assertViewHas('timeline', fn ($timeline): bool => $timeline->count() === 2
            && $timeline->pluck('kind')->all() === ['reentry', 'dispatch'] && (int) $timeline->last()->cycle_id === $last)
            ->assertDontSee('Instruccion del primer ingreso');
        $this->get($url.'?cycle=999999999')->assertNotFound();
        $this->get(route('containers.history.index', ['reentries' => 15]))->assertOk()
            ->assertViewHas('containers', fn ($rows): bool => $rows->total() === 1 && (int) $rows->first()->reentry_count === 15);
    }

    public function test_container_history_places_transfers_and_grouped_field_changes_between_ingress_and_exit(): void
    {
        $this->actingAs($this->admin);
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update([
            'entered_at' => '2026-10-01 08:00:00', 'created_at' => '2026-10-01 14:00:00', 'closed_at' => '2026-10-10 20:00:00',
        ]);
        foreach (['Marca' => ['CIMC', 'Triton'], 'Piloto' => ['Piloto anterior', '<img src=x onerror=alert(1)>']] as $field => [$before, $after]) {
            $id = DB::table('logs_eventos')->insertGetId(['contenedor_id' => $this->container, 'usuario_id' => $this->admin->id,
                'tipo_operacion_id' => 4, 'observaciones' => 'Cambio de '.$field, 'fecha_evento' => '2026-10-03 12:00:00', 'estado_id' => 2]);
            DB::table('logs_modificaciones')->insert(['evento_id' => $id, 'campo_modificado' => $field, 'valor_anterior' => $before, 'valor_nuevo' => $after]);
        }
        $id = DB::table('logs_eventos')->insertGetId(['contenedor_id' => $this->container, 'usuario_id' => $this->admin->id,
            'tipo_operacion_id' => 3, 'observaciones' => 'Cambio de estado', 'fecha_evento' => '2026-10-03 12:00:00', 'estado_id' => 2]);
        DB::table('logs_estatus')->insert(['evento_id' => $id, 'tipo_estatus_id' => 1,
            'valor_anterior_id' => DB::table('contenedores')->where('id', $this->container)->value('estatus_unidad_id'),
            'valor_nuevo_id' => DB::table('contenedores')->where('id', $this->container)->value('estatus_box_id')]);
        $id = DB::table('logs_eventos')->insertGetId(['contenedor_id' => $this->container, 'usuario_id' => $this->admin->id,
            'tipo_operacion_id' => 2, 'observaciones' => 'Traslado', 'fecha_evento' => '2026-10-04 14:00:00', 'estado_id' => 2]);
        $block = DB::table('contenedores')->where('id', $this->container)->value('bloque_id');
        DB::table('logs_traslados')->insert(['evento_id' => $id, 'bloque_anterior_id' => $block, 'posicion_anterior' => 1, 'bloque_nuevo_id' => $block, 'posicion_nueva' => 2]);
        $url = route('containers.history.show', $this->container);
        $this->get($url)->assertOk()->assertViewHas('timeline', function ($timeline): bool {
            $change = $timeline->firstWhere('kind', 'modification');

            return $timeline->pluck('kind')->all() === ['ingress', 'modification', 'transfer', 'dispatch']
                && count($change->snapshot['changes']) === 3 && $change->actor === $this->admin->username
                && $change->snapshot['changes'][0] === ['field' => 'Marca', 'before' => 'CIMC', 'after' => 'Triton']
                && (int) $timeline->firstWhere('kind', 'transfer')->cycle_id === $this->cycle;
        })->assertSee('aria-orientation="horizontal"', false)->assertSee('data-history-panel', false)
            ->assertSee('04/10/2026')->assertSee('14:00')->assertSee('Triton')->assertSee('OK QA')
            ->assertDontSee('<img src=x onerror=alert(1)>', false)->assertDontSee('history-transfer-summary', false);

        $foreignBranch = DB::table('sucursales')->insertGetId(['nombre' => 'Ciclo ambiguo '.uniqid(), 'estado_id' => 1]);
        $copy = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        unset($copy['id']);
        $copy['branch_id'] = $foreignBranch;
        DB::table('container_ingress_cycles')->insert($copy);
        $this->get($url)->assertOk()->assertViewHas('timeline', fn ($timeline): bool => $timeline->where('kind', 'modification')->isEmpty()
            && $timeline->where('kind', 'transfer')->isEmpty())->assertDontSee('Triton');
    }

    public function test_container_history_preserves_ingress_documents_and_details_after_later_changes(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('documents.ingress.ticket', ['cycle_id' => $this->cycle]))->assertNotFound();
        $container = Contenedor::findOrFail($this->container);
        $container->update(['marca' => 'Marca original', 'transportista' => 'Transporte original', 'conductor' => 'Piloto original',
            'placa_cabezal' => 'C123ABC', 'placa_chasis' => 'TC456DEF', 'observaciones_unidad' => 'Observacion historica de unidad']);
        $assignment = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        $assignment['reason'] = 'Nuevo ingreso de prueba';
        app(ContainerAuditAssignment::class)->start($this->admin, $container, $this->branch, $assignment, true);
        $cycle = $container->current_ingress_cycle_id;
        $container->update(['marca' => 'Marca posterior', 'conductor' => 'Piloto posterior']);
        $this->get(route('containers.history.show', ['container' => $this->container, 'cycle' => $cycle]))->assertOk()->assertSee('Marca original')->assertSee('Piloto original')
            ->assertSee('Observacion historica de unidad')->assertDontSee('Marca posterior')
            ->assertSee(route('documents.ingress.ticket', ['cycle_id' => $cycle]), false);
        $this->get(route('documents.ingress.ticket', ['cycle_id' => $cycle]))->assertOk()->assertSee('Marca original')->assertDontSee('Marca posterior');
        $pdf = $this->get(route('documents.ingress.ticket.pdf', ['cycle_id' => $cycle]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $foreign = DB::table('sucursales')->insertGetId(['nombre' => 'Documento privado '.uniqid(), 'estado_id' => 1]);
        $superadmin = User::create(['username' => 'QA_history_'.uniqid(), 'email' => uniqid().'@example.test', 'password' => Hash::make('Secret123!'),
            'rol_id' => 1, 'sucursal_id' => $foreign, 'estado_id' => 1]);
        $this->actingAs($superadmin)->withSession(['sucursal_activa' => $foreign]);
        $this->get(route('documents.ingress.ticket', ['cycle_id' => $cycle]))->assertNotFound();
        $this->get(route('documents.ingress.ticket.pdf', ['cycle_id' => $cycle]))->assertNotFound();
    }

    public function test_container_history_explains_open_audits_and_separates_original_and_correction_details(): void
    {
        $job = $this->start();
        DB::table('maintenance_jobs')->where('id', $job)->update(['work_performed' => 'Trabajo original conservado']);
        $round = DB::table('maintenance_corrections')->insertGetId(['job_id' => $job, 'number' => 1, 'requested_by' => $this->auditor->id,
            'reason' => 'Solicitud tecnica de la auditora', 'status' => 'submitted', 'diagnosis' => 'Diagnostico exclusivo de correccion',
            'work_performed' => 'Trabajo exclusivo de correccion', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('container_assignment_events')->insert(['container_id' => $this->container, 'ingress_cycle_id' => $this->cycle,
            'user_id' => $this->auditor->id, 'event_type' => 'external_audit_status', 'reason' => '',
            'after_data' => json_encode(['audit' => ['estatus_pyp' => 'En revision', 'informacion_adicional' => '']]), 'created_at' => now()]);
        $this->actingAs($this->admin)->get(route('containers.history.show', $this->container))->assertOk()
            ->assertSee('Revision en curso')->assertSee('Todavia no se habia emitido un dictamen')
            ->assertSee('Solicitud tecnica de la auditora')->assertDontSee('Vinculado al ingreso por la fecha del registro.')
            ->assertViewHas('timeline', function ($timeline) use ($round): bool {
                $repair = $timeline->firstWhere('kind', 'repair');
                $correction = $timeline->firstWhere('kind', 'correction_submitted');

                return $repair->sections['Trabajo realizado'] === 'Trabajo original conservado'
                    && $correction->sections['Trabajo realizado'] === 'Trabajo exclusivo de correccion'
                    && (int) $correction->round_id === $round && $correction->details['Intervencion'] === 'Correccion 1';
            });
    }

    public function test_operational_inventory_is_paginated_without_date_cutoff_and_exports_all_selected_rows_safely(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update(['fecha_ingreso' => '2020-01-01 08:00:00']);
        $copy = (array) DB::table('contenedores')->find($this->container);
        unset($copy['id']);
        $copy['current_ingress_cycle_id'] = null;
        $copy['naviera'] = '=1+1';
        for ($index = 0; $index < 104; $index++) {
            $copy['nombre'] = 'RPTU'.str_pad((string) $index, 7, '0', STR_PAD_LEFT);
            DB::table('contenedores')->insert($copy);
        }
        $this->actingAs($this->admin);
        $params = ['tipo' => 'inventario', 'per_page' => 25, 'columns' => [0, 9]];

        $this->get(route('reports.index', $params))->assertOk()
            ->assertViewHas('paginator', fn ($paginator): bool => $paginator->total() === 105 && $paginator->count() === 25)
            ->assertViewHas('headers', ['Contenedor', 'Naviera']);
        $this->get(route('reports.index', $params + ['page' => 5]))->assertOk()
            ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 5);
        $response = $this->get(route('reports.export', ['format' => 'xlsx'] + $params))->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'report-');
        try {
            file_put_contents($file, $response->streamedContent());
            $sheet = IOFactory::load($file)->getActiveSheet();
            $this->assertSame(111, $sheet->getHighestRow());
            $this->assertSame('=1+1', $sheet->getCell('B8')->getValue());
            $this->assertSame('s', $sheet->getCell('B8')->getDataType());
            $this->assertSame('0B5D57', $sheet->getStyle('A1')->getFill()->getStartColor()->getRGB());
            $this->assertSame('0E746B', $sheet->getStyle('A6')->getFill()->getStartColor()->getRGB());
            $this->assertStringContainsString('Generado por: '.$this->admin->username, $sheet->getCell('A4')->getValue());
            $this->assertSame('A7', $sheet->getFreezePane());
        } finally {
            unlink($file);
        }
        $pdf = $this->get(route('reports.export', ['format' => 'pdf', 'tipo' => 'inventario', 'columns' => [0], 'search' => 'RPTU0000000']))->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_operational_reports_preserve_ingress_and_dispatch_snapshots_after_reentry(): void
    {
        $event = ['container_id' => $this->container, 'ingress_cycle_id' => $this->cycle, 'user_id' => $this->admin->id,
            'reason' => 'Registro historico', 'created_at' => now()];
        DB::table('container_assignment_events')->insert($event + ['event_type' => 'ingress',
            'after_data' => json_encode(['cycle' => ['shipping_line_name' => 'Naviera original', 'audit_company_name' => 'Auditora original']])]);
        DB::table('container_assignment_events')->insert($event + ['event_type' => 'dispatch',
            'after_data' => json_encode(['document' => ['equipo' => 'EQUIPO-ORIGINAL', 'numero' => 'EIR-ORIGINAL', 'naviera' => 'Naviera salida',
                'conductor' => 'Piloto original', 'usuario' => 'Autor original', 'patio' => 'Patio de retiro original']])]);
        DB::table('contenedores')->where('id', $this->container)->update(['naviera' => 'Naviera actual', 'conductor' => 'Piloto actual']);
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['shipping_line_name' => 'Asignacion nueva']);
        $newCycle = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        unset($newCycle['id']);
        $newCycleId = DB::table('container_ingress_cycles')->insertGetId($newCycle);
        DB::table('contenedores')->where('id', $this->container)->update(['current_ingress_cycle_id' => $newCycleId]);
        $this->actingAs($this->admin);

        $this->get(route('reports.index', ['tipo' => 'ingresos']))->assertOk()->assertSee('Naviera original')->assertSee('Auditora original')->assertDontSee('Asignacion nueva');
        $this->get(route('reports.index', ['tipo' => 'despachos']))->assertOk()->assertSee('EIR-ORIGINAL')->assertSee('Piloto original')
            ->assertSee('Autor original')->assertSee('Patio de retiro original')->assertDontSee('Piloto actual');
        $this->get(route('reports.index', ['tipo' => 'despachos', 'search' => 'EQUIPO-ORIGINAL']))->assertOk()
            ->assertViewHas('paginator', fn ($paginator): bool => $paginator->total() === 1);
        $this->get(route('reports.index', ['tipo' => 'despachos', 'search' => '%']))->assertOk()
            ->assertViewHas('paginator', fn ($paginator): bool => $paginator->total() === 0);
        $this->get('/reportes/generar_despachos.php?modo=preview')->assertOk()->assertJsonPath('rows.0.2', 'EIR-ORIGINAL');
    }

    public function test_operational_reports_validate_filters_and_enforce_branch_and_export_permissions(): void
    {
        $this->actingAs($this->admin);
        $this->getJson(route('reports.index', ['tipo' => 'desconocido']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->getJson(route('reports.index', ['f_inicio' => '2026-10-10', 'f_fin' => '2026-10-01']))->assertUnprocessable()->assertJsonValidationErrors('f_fin');
        $this->getJson(route('reports.index', ['columns_selected' => 1]))->assertUnprocessable()->assertJsonValidationErrors('columns');
        $foreignBranch = DB::table('sucursales')->insertGetId(['nombre' => 'Reportes privados '.uniqid(), 'estado_id' => 1]);
        $foreignYard = DB::table('patios')->insertGetId(['nombre' => 'Patio privado', 'sucursal_id' => $foreignBranch, 'estado_id' => 1]);
        $this->getJson(route('reports.index', ['patio_id' => $foreignYard]))->assertUnprocessable()->assertJsonValidationErrors('patio_id');
        $this->withSession(['sucursal_activa' => $foreignBranch])->get(route('reports.index'))->assertForbidden();
        $this->withSession(['sucursal_activa' => $this->branch]);
        $this->admin->update(['permissions' => ['reports.view']]);
        $this->get(route('reports.index'))->assertOk()->assertDontSee('data-report-export', false);
        $this->get(route('reports.export', ['format' => 'xlsx']))->assertForbidden();
        $this->actingAs($this->auditor)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($this->mechanic)->get(route('reports.index'))->assertForbidden();
    }

    public function test_reports_filter_multiple_yards_and_blocks_consistently_in_preview_and_exports(): void
    {
        $this->actingAs($this->admin);
        $original = (array) DB::table('contenedores')->find($this->container);
        $firstBlock = (int) $original['bloque_id'];
        $firstYard = (int) DB::table('bloques')->where('id', $firstBlock)->value('patio_id');
        $ids = [$this->container];
        $yards = [$firstYard];
        $blocks = [$firstBlock];
        for ($index = 1; $index <= 2; $index++) {
            $yards[] = DB::table('patios')->insertGetId(['nombre' => 'Patio reporte '.$index, 'sucursal_id' => $this->branch, 'estado_id' => 1]);
            $blocks[] = DB::table('bloques')->insertGetId(['numero' => 1, 'patio_id' => $yards[$index], 'capacidad_maxima' => 25, 'capacidad_actual' => 1, 'estado_id' => 1]);
            $copy = $original;
            unset($copy['id']);
            $copy['current_ingress_cycle_id'] = null;
            $copy['nombre'] = 'RPMU000000'.$index;
            $copy['bloque_id'] = $blocks[$index];
            $ids[] = DB::table('contenedores')->insertGetId($copy);
        }
        foreach ($ids as $index => $id) {
            $event = DB::table('logs_eventos')->insertGetId(['contenedor_id' => $id, 'usuario_id' => $this->admin->id, 'tipo_operacion_id' => 2,
                'observaciones' => 'Movimiento de prueba', 'fecha_evento' => now()->format('Y-m-d H:i:s'), 'estado_id' => 1]);
            DB::table('logs_traslados')->insert(['evento_id' => $event, 'bloque_anterior_id' => $blocks[$index], 'posicion_anterior' => 1,
                'bloque_nuevo_id' => $blocks[$index], 'posicion_nueva' => 2]);
            DB::table('logs_modificaciones')->insert(['evento_id' => $event, 'campo_modificado' => 'Marca', 'valor_anterior' => 'Anterior', 'valor_nuevo' => 'Nueva']);
        }
        $params = ['patio_ids' => array_slice($yards, 0, 2), 'bloque_ids' => array_slice($blocks, 0, 2), 'tipo' => 'inventario'];
        foreach (['inventario', 'movimientos', 'traslados', 'cambios'] as $type) {
            $this->get(route('reports.index', array_replace($params, ['tipo' => $type])))->assertOk()
                ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 2)
                ->assertViewHas('selectedYards', array_slice($yards, 0, 2))->assertViewHas('selectedBlocks', array_slice($blocks, 0, 2));
        }
        $response = $this->get(route('reports.export', $params + ['format' => 'xlsx']))->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'report-locations-');
        try {
            file_put_contents($file, $response->streamedContent());
            $sheet = IOFactory::load($file)->getActiveSheet();
            $this->assertSame(8, $sheet->getHighestRow());
            $names = [$sheet->getCell('A7')->getValue(), $sheet->getCell('A8')->getValue()];
            $this->assertContains($original['nombre'], $names);
            $this->assertContains('RPMU0000001', $names);
            $this->assertNotContains('RPMU0000002', $names);
        } finally {
            unlink($file);
        }
        $this->get(route('reports.export', $params + ['format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('reports.index', ['tipo' => 'inventario']))->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->count() === 3);
        $this->getJson(route('reports.index', array_replace($params, ['bloque_ids' => [$blocks[2]]])))->assertUnprocessable();
        $foreignBranch = DB::table('sucursales')->insertGetId(['nombre' => 'Patio restringido '.uniqid(), 'estado_id' => 1]);
        $foreignYard = DB::table('patios')->insertGetId(['nombre' => 'Patio externo', 'sucursal_id' => $foreignBranch, 'estado_id' => 1]);
        $foreignBlock = DB::table('bloques')->insertGetId(['numero' => 1, 'patio_id' => $foreignYard, 'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1]);
        $this->getJson(route('reports.index', ['patio_ids' => [$foreignYard]]))->assertUnprocessable()->assertJsonValidationErrors('patio_ids.0');
        $this->getJson(route('reports.export', ['format' => 'xlsx', 'bloque_ids' => [$foreignBlock]]))->assertUnprocessable()->assertJsonValidationErrors('bloque_ids.0');
        $this->getJson(route('reports.export', ['format' => 'pdf', 'patio_ids' => [$firstYard, $firstYard]]))->assertUnprocessable()->assertJsonValidationErrors('patio_ids.0');
    }

    public function test_report_location_groups_combine_specific_blocks_with_all_blocks_of_another_yard(): void
    {
        $this->actingAs($this->admin);
        $original = (array) DB::table('contenedores')->find($this->container);
        $firstBlock = (int) $original['bloque_id'];
        $firstYard = (int) DB::table('bloques')->where('id', $firstBlock)->value('patio_id');
        $secondYard = DB::table('patios')->insertGetId(['nombre' => 'Patio agrupado', 'sucursal_id' => $this->branch, 'estado_id' => 1]);
        $blocks = [$firstBlock];
        foreach ([$firstYard, $secondYard, $secondYard] as $index => $yard) {
            $blocks[] = DB::table('bloques')->insertGetId(['numero' => $index + 2, 'patio_id' => $yard, 'capacidad_maxima' => 25, 'capacidad_actual' => 1, 'estado_id' => 1]);
            $copy = $original;
            unset($copy['id']);
            $copy['nombre'] = 'GRPU000000'.$index;
            $copy['bloque_id'] = end($blocks);
            $copy['current_ingress_cycle_id'] = null;
            DB::table('contenedores')->insert($copy);
        }
        $locations = [['patio_id' => $firstYard, 'bloque_ids' => [$firstBlock]], ['patio_id' => $secondYard]];
        $params = ['tipo' => 'inventario', 'locations' => $locations];
        $this->get(route('reports.index', $params))->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->count() === 3
            && ! $rows->contains(fn ($row): bool => $row[0] === 'GRPU0000000'))
            ->assertSee('data-report-add-location', false)->assertViewHas('selectedLocations', [
                ['patio_id' => $firstYard, 'bloque_ids' => [$firstBlock]], ['patio_id' => $secondYard, 'bloque_ids' => []],
            ]);
        $response = $this->get(route('reports.export', $params + ['format' => 'xlsx']))->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'report-groups-');
        try {
            file_put_contents($file, $response->streamedContent());
            $sheet = IOFactory::load($file)->getActiveSheet();
            $this->assertSame(9, $sheet->getHighestRow());
            $names = array_column($sheet->rangeToArray('A7:A9'), 0);
            $this->assertNotContains('GRPU0000000', $names);
            $this->assertContains('GRPU0000001', $names);
            $this->assertContains('GRPU0000002', $names);
        } finally {
            unlink($file);
        }
        $this->get(route('reports.export', $params + ['format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson(route('reports.index', ['locations' => [['patio_id' => $firstYard, 'bloque_ids' => [$blocks[2]]], ['patio_id' => $secondYard]]]))->assertUnprocessable();
        $this->getJson(route('reports.index', ['locations' => [['bloque_ids' => [$firstBlock]]]]))->assertUnprocessable();
        $this->getJson(route('reports.index', ['locations' => [['patio_id' => $firstYard], ['patio_id' => $firstYard]]]))->assertUnprocessable()->assertJsonValidationErrors('locations.0.patio_id');
        $this->get(route('reports.index', ['locations' => [['patio_id' => '']]]))->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->count() === 4);
        $this->getJson(route('reports.index', ['locations' => [['patio_id' => 999999999]]]))->assertUnprocessable()->assertJsonValidationErrors('locations.0.patio_id');
    }

    public function test_report_builder_uses_the_original_configuration_and_preview_layout_for_all_report_types(): void
    {
        $this->actingAs($this->admin);
        foreach (['inventario', 'ingresos', 'despachos', 'movimientos', 'auditorias', 'reparaciones', 'costos', 'traslados', 'cambios', 'historial'] as $type) {
            $this->get(route('reports.index', ['tipo' => $type]))->assertOk()->assertSee('Generador de reportes')
                ->assertSee('reports-options', false)->assertSee('reports-preview', false)->assertSee('Vista previa')
                ->assertSee('data-report-columns-all', false)->assertSee('data-report-columns-none', false)
                ->assertSee('formaction="'.route('reports.export', ['format' => 'xlsx']).'"', false)
                ->assertSee('formaction="'.route('reports.export', ['format' => 'pdf']).'"', false);
        }
        $html = view('reports.pdf', ['title' => 'Reporte QA', 'meta' => ['Sucursal' => '<script>no ejecutar</script>'],
            'logo' => null, 'headers' => ['Observaciones'], 'rows' => [['<img src=x onerror=alert(1)>']]])->render();
        $this->assertStringContainsString('header-band', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function test_audit_reports_read_all_cycle_events_in_local_dates_and_export_beyond_100(): void
    {
        $events = [];
        for ($index = 0; $index < 105; $index++) {
            $events[] = ['container_id' => $this->container, 'ingress_cycle_id' => $this->cycle, 'user_id' => $this->auditor->id,
                'event_type' => 'external_audit_status', 'reason' => 'Revision de prueba', 'created_at' => '2026-10-15 06:00:00',
                'after_data' => json_encode(['audit' => ['estatus_pyp' => $index % 2 ? 'Aprobado' : 'Rechazado',
                    'external_audit_company_id' => $this->auditor->external_audit_company_id,
                    'inspector_name' => 'Inspector historico', 'informacion_adicional' => '=Observacion '.$index, 'maintenance_correction_id' => $index]])];
        }
        DB::table('container_assignment_events')->insert($events);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Rechazado', 'fecha_registro' => '2026-10-15 06:00:00']);
        DB::table('container_assignment_events')->insert(array_replace($events[0], ['after_data' => json_encode(['audit' => ['estatus_pyp' => 'En revision']])]));
        foreach (['2026-10-15 05:59:59', '2026-10-16 06:00:00'] as $date) {
            DB::table('container_assignment_events')->insert(array_replace($events[0], ['created_at' => $date]));
        }
        $copy = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        unset($copy['id']);
        $newCycle = DB::table('container_ingress_cycles')->insertGetId($copy);
        DB::table('contenedores')->where('id', $this->container)->update(['current_ingress_cycle_id' => $newCycle]);
        $foreignBranch = DB::table('sucursales')->insertGetId(['nombre' => 'Auditorias restringidas '.uniqid(), 'estado_id' => 1]);
        $foreignCycle = DB::table('container_ingress_cycles')->insertGetId(array_replace($copy, ['branch_id' => $foreignBranch]));
        DB::table('container_assignment_events')->insert(array_replace($events[0], ['ingress_cycle_id' => $foreignCycle]));
        $this->actingAs($this->admin);
        $params = ['tipo' => 'auditorias', 'f_inicio' => '2026-10-15', 'f_fin' => '2026-10-15', 'per_page' => 25];
        $this->get(route('reports.index', $params))->assertOk()
            ->assertViewHas('paginator', fn ($pages): bool => $pages->total() === 105 && $pages->count() === 25)
            ->assertViewHas('rows', fn ($rows): bool => (int) $rows->first()[2] === $this->cycle && $rows->first()[0] === '15/10/2026 00:00')
            ->assertSee('Inspector historico')->assertDontSee('Aprobacion anterior');
        $this->get(route('reports.index', $params + ['page' => 5]))->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->count() === 5);
        $this->get(route('reports.index', $params + ['search' => '%']))->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->isEmpty());
        $response = $this->get(route('reports.export', $params + ['format' => 'xlsx']))->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'audit-report-');
        try {
            file_put_contents($file, $response->streamedContent());
            $sheet = IOFactory::load($file)->getActiveSheet();
            $this->assertSame(111, $sheet->getHighestRow());
            $this->assertSame('=Observacion 104', $sheet->getCell('H7')->getValue());
            $this->assertSame('s', $sheet->getCell('H7')->getDataType());
        } finally {
            unlink($file);
        }
        $this->get(route('reports.export', $params + ['format' => 'pdf', 'search' => 'no-existe']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['fecha_registro' => '2026-10-15 06:01:00']);
        $this->get(route('reports.index', $params))->assertOk()->assertViewHas('paginator', fn ($pages): bool => $pages->total() === 106);
    }

    public function test_maintenance_reports_keep_original_and_correction_snapshots_and_partial_costs_separate(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['repair_type' => 'Servicio original', 'service_unit_cost' => '75.00',
            'service_currency' => 'GTQ', 'usd_gtq_rate' => '7.500000', 'exchange_rate_date' => '2026-10-15',
            'status' => 'completed', 'submitted_at' => now()]);
        DB::table('maintenance_materials')->insert(['job_id' => $id, 'part_id' => $this->part, 'name' => 'Material original',
            'unit' => 'Unidad', 'quantity' => 2, 'unit_cost' => '10.00', 'currency' => 'GTQ']);
        foreach ([1, 2] as $number) {
            DB::table('maintenance_corrections')->insert(['job_id' => $id, 'number' => $number, 'requested_by' => $this->auditor->id,
                'reason' => 'Solicitud historica '.$number, 'status' => 'submitted', 'version' => 1,
                'diagnosis' => 'Diagnostico correccion '.$number, 'work_performed' => 'Trabajo correccion '.$number,
                'repair_service_snapshot' => json_encode(['name' => 'Servicio correccion '.$number, 'unit_cost' => $number === 1 ? '5.00' : null,
                    'currency' => 'USD', 'exchange' => ['usd_gtq_rate' => '8.000000', 'exchange_rate_date' => '2026-10-16']]),
                'materials' => json_encode([['name' => 'Material correccion', 'quantity' => '1.500', 'unit_cost' => '2.00', 'currency' => 'USD']]),
                'created_at' => now(), 'updated_at' => now(), 'submitted_at' => now()]);
        }
        DB::table('repair_services')->where('id', $this->service)->update(['reference_cost' => '999.00']);
        $foreignBranch = DB::table('sucursales')->insertGetId(['nombre' => 'Mantenimiento privado '.uniqid(), 'estado_id' => 1]);
        $private = (array) DB::table('maintenance_jobs')->find($id);
        unset($private['id']);
        DB::table('maintenance_jobs')->insert(array_replace($private, ['branch_id' => $foreignBranch, 'repair_type' => 'Servicio privado']));
        $this->actingAs($this->admin);
        $params = ['f_inicio' => now()->subDay()->toDateString(), 'f_fin' => now()->toDateString()];
        $this->get(route('reports.index', $params + ['tipo' => 'reparaciones']))->assertOk()
            ->assertViewHas('paginator', fn ($pages): bool => $pages->total() === 3)
            ->assertSee('Reparacion original')->assertSee('Correccion 1')->assertSee('Correccion 2')
            ->assertSee('Diagnostico correccion 1')->assertDontSee('Servicio privado');
        $response = $this->get(route('reports.index', $params + ['tipo' => 'costos']))->assertOk();
        $rows = $response->viewData('rows')->keyBy(fn ($row) => $row[4]);
        $this->assertEquals(95, $rows['Reparacion original'][9]);
        $this->assertEquals(64, $rows['Correccion 1'][9]);
        $this->assertSame('8.000000', $rows['Correccion 1'][11]);
        $this->assertSame('2026-10-16', $rows['Correccion 1'][12]);
        $this->assertSame('Pendiente', $rows['Correccion 2'][7]);
        $this->assertEquals(24, $rows['Correccion 2'][9]);
        $this->assertSame('Parcial: faltan precios o conversion', $rows['Correccion 2'][10]);
        $response->assertSee('Q 95.00')->assertSee('Q 64.00');
        $this->get(route('reports.index', $params + ['tipo' => 'costos', 'search' => '%']))->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->isEmpty());
        $export = $this->get(route('reports.export', $params + ['format' => 'xlsx', 'tipo' => 'costos', 'columns' => [4, 9]]))->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'maintenance-report-');
        try {
            file_put_contents($file, $export->streamedContent());
            $sheet = IOFactory::load($file)->getActiveSheet();
            $this->assertSame(9, $sheet->getHighestRow());
            $values = array_column($sheet->rangeToArray('B7:B9', null, true, false), 0);
            $this->assertContains(95, $values);
            $this->assertContains(64, $values);
            $this->assertContains(24, $values);
            $this->assertSame('n', $sheet->getCell('B7')->getDataType());
            $this->assertSame('"Q "#,##0.00', $sheet->getCell('B7')->getStyle()->getNumberFormat()->getFormatCode());
        } finally {
            unlink($file);
        }
        $this->get(route('reports.export', $params + ['format' => 'pdf', 'tipo' => 'reparaciones']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->admin->update(['permissions' => ['reports.view']]);
        $this->get(route('reports.export', ['format' => 'xlsx', 'tipo' => 'costos']))->assertForbidden();
        $this->actingAs($this->mechanic)->get(route('reports.index', ['tipo' => 'reparaciones']))->assertForbidden();
    }

    public function test_legacy_report_previews_are_paginated_and_exports_do_not_stop_at_100(): void
    {
        $block = (int) DB::table('contenedores')->where('id', $this->container)->value('bloque_id');
        for ($index = 0; $index < 105; $index++) {
            $event = DB::table('logs_eventos')->insertGetId(['contenedor_id' => $this->container, 'usuario_id' => $this->admin->id,
                'tipo_operacion_id' => 2, 'fecha_evento' => '2026-10-15 12:00:00', 'observaciones' => 'Movimiento '.$index, 'estado_id' => 1]);
            DB::table('logs_modificaciones')->insert(['evento_id' => $event, 'campo_modificado' => 'Marca', 'valor_anterior' => 'Antes', 'valor_nuevo' => 'Despues '.$index]);
            DB::table('logs_traslados')->insert(['evento_id' => $event, 'bloque_anterior_id' => $block, 'bloque_nuevo_id' => $block,
                'posicion_anterior' => 1, 'posicion_nueva' => 2]);
        }
        $this->actingAs($this->admin);
        foreach (['movimientos', 'traslados', 'cambios', 'historial'] as $type) {
            $params = ['tipo' => $type, 'f_inicio' => '2026-10-15', 'f_fin' => '2026-10-15', 'per_page' => 25, 'contenedor_id' => $this->container];
            $this->get(route('reports.index', $params))->assertOk()
                ->assertViewHas('paginator', fn ($pages): bool => $pages->total() === 105 && $pages->count() === 25)
                ->assertDontSee('Consulta limitada a 100 registros');
            $this->get(route('reports.index', $params + ['page' => 5]))->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->count() === 5);
            $response = $this->get(route('reports.export', $params + ['format' => 'xlsx']))->assertOk();
            $file = tempnam(sys_get_temp_dir(), 'legacy-report-');
            try {
                file_put_contents($file, $response->streamedContent());
                $this->assertSame(111, IOFactory::load($file)->getActiveSheet()->getHighestRow());
            } finally {
                unlink($file);
            }
        }
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

    public function test_auditor_repair_link_and_old_read_only_urls_use_the_current_detail_view(): void
    {
        $job = $this->start();
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'completed']);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'En revision']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $auditUrl = route('pyp-audits.edit', ['container' => $this->container, 'patio_id' => 3]);
        $returnUrl = '/contenedores/'.$this->container.'/pyp?patio_id=3';
        $detail = route('corrections.detail', ['job' => $job, 'return_to' => $returnUrl]);
        $this->actingAs($this->auditor)->get($auditUrl)->assertOk()
            ->assertSee(e($detail), false)->assertDontSee('Ver reparación y correcciones');
        foreach ([$this->auditor, $this->admin, $this->mechanic] as $user) {
            $this->actingAs($user)->get(route('corrections.show', ['job' => $job, 'return_to' => $returnUrl]))
                ->assertRedirect($detail);
            $this->get($detail)->assertOk()->assertViewIs('notifications.repair')
                ->assertSee('Detalle de reparación')->assertDontSee('Historial de correcciones');
        }
        $this->actingAs($this->other)->get(route('corrections.show', $job))->assertForbidden();
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'returned_for_repair']);
        $this->actingAs($this->mechanic)->get(route('corrections.show', $job))->assertOk()
            ->assertViewIs('maintenance.corrections');
        $this->actingAs($this->auditor)->get(route('corrections.show', ['job' => $job, 'return_to' => $returnUrl]))
            ->assertRedirect($detail);
    }

    public function test_auditor_repair_link_opens_the_latest_delivered_correction_not_drafts_or_other_jobs(): void
    {
        $job = $this->start();
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'completed']);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'En revision']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $round = ['job_id' => $job, 'requested_by' => $this->auditor->id, 'reason' => 'Revisar el sello de la puerta',
            'status' => 'approved', 'number' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        DB::table('maintenance_corrections')->insert($round);
        $latest = DB::table('maintenance_corrections')->insertGetId(array_replace($round, [
            'number' => 2, 'status' => 'submitted', 'diagnosis' => 'Diagnostico de la ultima correccion enviada',
            'work_performed' => 'Trabajo de la ultima correccion enviada',
            'materials' => json_encode([['name' => 'Material de la correccion', 'unit' => 'Unidad', 'quantity' => 2, 'unit_cost' => 7, 'currency' => 'GTQ']], JSON_THROW_ON_ERROR),
            'photos_before' => json_encode([['path' => 'correccion-inicial.jpg', 'name' => 'correccion-inicial.jpg', 'mime' => 'image/jpeg']], JSON_THROW_ON_ERROR),
            'photos' => json_encode([['path' => 'correccion-final.jpg', 'name' => 'correccion-final.jpg', 'mime' => 'image/jpeg']], JSON_THROW_ON_ERROR),
        ]));
        DB::table('maintenance_corrections')->insert(array_replace($round, ['number' => 3, 'status' => 'draft']));
        $other = (array) DB::table('maintenance_jobs')->find($job);
        unset($other['id']);
        $other['ingress_cycle_id'] = null;
        $otherJob = DB::table('maintenance_jobs')->insertGetId($other);
        DB::table('maintenance_corrections')->insert(array_replace($round, ['job_id' => $otherJob, 'number' => 99, 'status' => 'submitted']));
        $return = '/contenedores/'.$this->container.'/pyp?patio_id=3&bloque_id=5';
        $detail = route('corrections.detail', ['job' => $job, 'intervention' => $latest, 'return_to' => $return]);
        $original = route('corrections.detail', ['job' => $job, 'return_to' => $return]);

        $this->actingAs($this->auditor);
        foreach (['submitted', 'approved', 'rejected'] as $status) {
            DB::table('maintenance_corrections')->where('id', $latest)->update(['status' => $status]);
            $this->get($return)->assertOk()->assertViewHas('maintenanceCorrectionId', $latest)
                ->assertSee('Ver corrección')->assertSee(e($detail), false)->assertDontSee(e($original), false);
        }
        $this->get($detail)->assertOk()->assertViewHas('selectedIntervention', fn ($round): bool => (int) $round->id === $latest)
            ->assertViewHas('returnUrl', $return)->assertSee('Diagnostico de la ultima correccion enviada')
            ->assertSee('Trabajo de la ultima correccion enviada')->assertSee('Material de la correccion')
            ->assertViewHas('evidence', fn ($photos): bool => $photos->count() === 2
                && $photos->firstWhere('stage', 'before')->original_name === 'correccion-inicial.jpg'
                && $photos->firstWhere('stage', 'after')->original_name === 'correccion-final.jpg')
            ->assertDontSee('Confirmar trabajo realizado')
            ->assertDontSee('Actualizar estados del contenedor');
    }

    public function test_auditor_repair_link_keeps_the_original_when_only_a_draft_correction_exists(): void
    {
        $job = $this->start();
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'completed']);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'En revision']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $draft = DB::table('maintenance_corrections')->insertGetId([
            'job_id' => $job, 'number' => 1, 'requested_by' => $this->auditor->id, 'reason' => 'Observacion pendiente',
            'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $return = '/contenedores/'.$this->container.'/pyp';
        $detail = route('corrections.detail', ['job' => $job, 'return_to' => $return]);

        $this->actingAs($this->auditor)->get($return)->assertOk()->assertViewHas('maintenanceCorrectionId', null)
            ->assertSee('Ver reparación')->assertDontSee('Ver corrección')->assertSee(e($detail), false);
        $this->get(route('corrections.detail', ['job' => $job, 'intervention' => $draft]))->assertNotFound();
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
            $this->get(route('corrections.rounds', $id))->assertOk()->assertViewHas('rounds', fn ($rounds): bool => $rounds->total() === 0);
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
        $this->get(route('corrections.rounds', $id))->assertForbidden();
        $this->get(route('corrections.original-photo', ['job' => $id, 'photo' => 1]))->assertForbidden();
        $reader->update(['permissions' => ['containers.view']]);
        $this->actingAs($reader->fresh())->withSession(['sucursal_activa' => 0])->get($url)->assertForbidden();
    }

    public function test_repair_options_link_to_corrections_only_when_visible_rounds_exist(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'completed']);
        $history = route('corrections.history', ['job' => $id, 'filters' => ['status' => 'completed'], 'per_page' => 5]);
        $rounds = route('corrections.rounds', ['job' => $id, 'return_to' => parse_url($history, PHP_URL_PATH).'?'.parse_url($history, PHP_URL_QUERY)]);
        $this->get($history)->assertOk()->assertSee('data-record-options', false)->assertSee('Ver reparación')->assertDontSee(e($rounds), false);
        DB::table('maintenance_corrections')->insert([
            'job_id' => $id, 'number' => 1, 'requested_by' => $this->auditor->id, 'reason' => 'Observacion pendiente de corregir',
            'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get($history)->assertOk()->assertSee(e($rounds), false);
        $this->actingAs($this->admin)->get($history)->assertOk()->assertDontSee(e($rounds), false);
        $this->actingAs($this->auditor)->get($history)->assertOk()->assertDontSee(e($rounds), false);
        DB::table('maintenance_corrections')->where('job_id', $id)->update(['status' => 'submitted']);
        $this->get($history)->assertOk()->assertSee(e($rounds), false);
        $this->get(route('corrections.detail', $id))->assertOk()->assertDontSee('Intervenciones');
    }

    public function test_correction_history_is_paginated_filtered_and_keeps_navigation_context(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'completed']);
        for ($number = 1; $number <= 13; $number++) {
            DB::table('maintenance_corrections')->insert([
                'job_id' => $id, 'number' => $number, 'requested_by' => $this->auditor->id,
                'reason' => 'Observacion de la auditora '.$number, 'status' => $number === 13 ? 'draft' : ($number === 1 ? 'approved' : 'submitted'),
                'version' => 1, 'created_at' => '2026-10-22 03:30:00', 'updated_at' => now(),
            ]);
        }
        $return = '/reparaciones/'.$id.'/historial?filters%5Bstatus%5D=completed&per_page=5';
        $url = route('corrections.rounds', ['job' => $id, 'return_to' => $return]);
        $this->get($url)->assertOk()->assertViewIs('maintenance.correction-history')->assertSee('Correcciones')
            ->assertViewHas('returnUrl', $return)->assertViewHas('rounds', fn ($rounds): bool => $rounds->total() === 13 && $rounds->count() === 10)
            ->assertDontSee('data-correction-form', false)->assertDontSee('Continuar corrección')->assertDontSee('Reparación original');
        $this->get($url.'&page=2')->assertOk()->assertViewHas('rounds', fn ($rounds): bool => $rounds->count() === 3);
        $this->get($url.'&q=21%2F10%2F2026')->assertOk()->assertViewHas('rounds', fn ($rounds): bool => $rounds->total() === 13);
        $this->get($url.'&filters[status]=approved')->assertOk()->assertViewHas('rounds', fn ($rounds): bool => $rounds->total() === 1 && $rounds->first()->number === 1);
        $this->get($url.'&filters[requester]='.$this->other->id)->assertOk()->assertViewHas('rounds', fn ($rounds): bool => $rounds->total() === 0);
        $this->get($url.'&sort=number&order=desc&per_page=5')->assertOk()->assertViewHas('rounds', fn ($rounds): bool => $rounds->perPage() === 5 && $rounds->first()->number === 13);
        $this->get($url.'&sort=invalid&per_page=999')->assertOk()->assertViewHas('rounds', fn ($rounds): bool => $rounds->perPage() === 10);
        $round = DB::table('maintenance_corrections')->where('job_id', $id)->where('number', 1)->value('id');
        $detailReturn = '/reparaciones/'.$id.'/correcciones/historial?filters%5Bstatus%5D=approved&return_to='.rawurlencode($return);
        $this->get(route('corrections.detail', ['job' => $id, 'intervention' => $round, 'return_to' => $detailReturn]))->assertOk()
            ->assertViewHas('returnUrl', $detailReturn)->assertViewHas('selectedIntervention', fn ($item): bool => (int) $item->id === (int) $round)
            ->assertDontSee('Intervenciones')->assertDontSee('Confirmar trabajo realizado')->assertDontSee('Actualizar estados del contenedor');
        $this->get(route('corrections.rounds', ['job' => $id, 'return_to' => 'https://example.com']))->assertOk()
            ->assertViewHas('returnUrl', '/reparaciones/'.$id.'/historial');
    }

    public function test_correction_history_respects_job_ownership_company_branch_and_draft_visibility(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'completed']);
        foreach ([1 => 'submitted', 2 => 'draft'] as $number => $status) {
            DB::table('maintenance_corrections')->insert([
                'job_id' => $id, 'number' => $number, 'requested_by' => $this->auditor->id, 'reason' => 'Observacion '.$number,
                'status' => $status, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $copy = (array) DB::table('maintenance_jobs')->find($id);
        unset($copy['id']);
        $copy['mechanic_id'] = $this->other->id;
        $otherJob = DB::table('maintenance_jobs')->insertGetId($copy);
        $foreignRound = DB::table('maintenance_corrections')->insertGetId([
            'job_id' => $otherJob, 'number' => 1, 'requested_by' => $this->auditor->id, 'reason' => 'Otra reparacion',
            'status' => 'submitted', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = route('corrections.rounds', $id);
        $this->get($url)->assertOk()->assertViewHas('rounds', fn ($rounds): bool => $rounds->total() === 2);
        $this->get(route('corrections.detail', ['job' => $id, 'intervention' => $foreignRound]))->assertNotFound();
        $this->get(route('corrections.rounds', $otherJob))->assertForbidden();
        $this->actingAs($this->other)->get($url)->assertForbidden();
        foreach ([$this->admin, $this->auditor] as $user) {
            $this->actingAs($user)->get($url)->assertOk()->assertViewHas('rounds', fn ($rounds): bool => $rounds->total() === 1)
                ->assertViewHas('filterOptions', fn (array $options): bool => ! isset($options['status']['draft']));
            $this->get($url.'?filters[status]=draft')->assertOk()->assertViewHas('rounds', fn ($rounds): bool => $rounds->isEmpty());
        }
        $this->withSession(['sucursal_activa' => 0])->get($url)->assertForbidden();
        $company = DB::table('external_audit_companies')->insertGetId(['name' => 'Otra empresa', 'name_key' => uniqid(), 'status' => 1]);
        $this->auditor->update(['external_audit_company_id' => $company]);
        $this->actingAs($this->auditor)->withSession(['sucursal_activa' => $this->branch])->get($url)->assertNotFound();
        $this->app['auth']->guard()->logout();
        $this->get($url)->assertRedirect(route('login'));
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
        $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSee('Intervenciones')
            ->assertSee('Solicitud de corrección')->assertSee('Se corrigio la fuga')->assertDontSee('Trabajo original')
            ->assertDontSee('Historial de correcciones')->assertDontSee('data-correction-form', false)
            ->assertViewHas('returnUrl', '/reparaciones/'.$id.'/correcciones/historial')
            ->assertViewHas('materials', fn ($materials): bool => $materials->isEmpty());
        $this->get(route('corrections.detail', $id))->assertOk()->assertSee('Trabajo original')->assertSee('Diagnostico original');
        $this->get(route('corrections.detail', ['job' => $id, 'intervention' => $draft]))->assertNotFound();
        $this->get(route('corrections.detail', ['job' => $id, 'intervention' => 99999999]))->assertNotFound();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'diagnosis' => 'Diagnostico original', 'work_performed' => 'Trabajo original']);
    }

    public function test_pending_correction_detail_shows_the_requested_audit_without_empty_mechanic_steps(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'returned_for_repair']);
        $image = UploadedFile::fake()->image('solicitud.jpg');
        $path = $image->storeAs('audit-requests', 'solicitud.jpg', 'local');
        $reason = 'Revisar el sello inferior y corregir <script>alert("x")</script> antes de entregar.';
        $round = DB::table('maintenance_corrections')->insertGetId([
            'job_id' => $id, 'number' => 1, 'requested_by' => $this->auditor->id, 'reason' => $reason,
            'audit_photos' => json_encode([['name' => 'solicitud.jpg', 'path' => $path, 'mime' => 'image/jpeg']], JSON_THROW_ON_ERROR),
            'status' => 'draft', 'version' => 1, 'created_at' => '2026-10-23 21:17:00', 'updated_at' => now(),
        ]);
        $return = '/reparaciones/'.$id.'/correcciones/historial?page=2';
        $url = route('corrections.detail', ['job' => $id, 'intervention' => $round, 'return_to' => $return]);

        $response = $this->get($url)->assertOk()->assertViewIs('maintenance.correction-request')->assertViewHas('returnUrl', $return)
            ->assertSee('Dictamen de auditoría')->assertSee('Rechazado')->assertSee($this->auditor->username)
            ->assertSee('23/10/2026 15:17')->assertSee('Naviera mantenimiento')->assertSee('solicitud.jpg')
            ->assertSee($reason)->assertDontSee($reason, false)->assertDontSee('Diagnóstico y ubicación del daño')
            ->assertDontSee('data-notification-review', false)->assertDontSee('data-correction-form', false);
        $this->assertSame(1, substr_count($response->getContent(), e($reason)));
        $photoUrl = route('corrections.audit-photo', ['job' => $id, 'round' => $round, 'photo' => 0]);
        $response->assertSee(e($photoUrl), false);
        $this->get($photoUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->other)->get($url)->assertForbidden();
        $this->actingAs($this->auditor)->get($url)->assertNotFound();
    }

    public function test_delivered_correction_keeps_its_request_in_a_modal_and_does_not_use_it_as_diagnosis(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'completed']);
        $round = DB::table('maintenance_corrections')->insertGetId([
            'job_id' => $id, 'number' => 1, 'requested_by' => $this->auditor->id, 'reason' => 'Solicitud antigua sin fotografias que debe conservarse.',
            'work_performed' => 'Trabajo entregado de esta correccion', 'status' => 'submitted', 'version' => 1,
            'created_at' => '2026-10-22 21:45:00', 'updated_at' => now(),
        ]);
        DB::table('maintenance_corrections')->insert([
            'job_id' => $id, 'number' => 2, 'requested_by' => $this->auditor->id, 'reason' => 'Solicitud de otra ronda que no debe aparecer.',
            'audit_photos' => json_encode([['name' => 'otra-ronda.jpg', 'path' => 'otra-ronda.jpg', 'mime' => 'image/jpeg']], JSON_THROW_ON_ERROR),
            'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = route('corrections.detail', ['job' => $id, 'intervention' => $round]);

        foreach ([$this->mechanic, $this->admin, $this->auditor] as $user) {
            $this->actingAs($user)->get($url)->assertOk()->assertViewIs('notifications.repair')
                ->assertViewHas('job', fn ($job): bool => $job->diagnosis === null)
                ->assertViewHas('requestContext', fn (array $context): bool => $context['photos'] === [] && $context['inspector'] === $this->auditor->username)
                ->assertSee('aria-controls="repairCorrectionRequest"', false)->assertSee('id="repairCorrectionRequest"', false)
                ->assertSee('Dictamen de auditoría')->assertSee('Sin fotografías adjuntas.')
                ->assertSee('Solicitud antigua sin fotografias que debe conservarse.')->assertSee('Trabajo entregado de esta correccion')
                ->assertDontSee('Solicitud de otra ronda que no debe aparecer.')->assertDontSee('otra-ronda.jpg')
                ->assertDontSee('class="correction-request"', false)->assertDontSee('Confirmar trabajo realizado');
        }
        $path = UploadedFile::fake()->image('foto-solicitada.jpg')->storeAs('audit-requests', 'foto-solicitada.jpg', 'local');
        DB::table('maintenance_corrections')->where('id', $round)->update([
            'audit_photos' => json_encode([['name' => 'foto-solicitada.jpg', 'path' => $path, 'mime' => 'image/jpeg']], JSON_THROW_ON_ERROR),
        ]);
        $photoUrl = route('corrections.audit-photo', ['job' => $id, 'round' => $round, 'photo' => 0]);
        $this->get($url)->assertOk()->assertSee(e($photoUrl), false)->assertSee('foto-solicitada.jpg')->assertDontSee('otra-ronda.jpg')
            ->assertViewHas('requestContext', fn (array $context): bool => count($context['photos']) === 1);
        $this->get($photoUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round, 'diagnosis' => null]);
    }

    public function test_supervisor_charts_use_current_available_status_and_exclusive_state_counts(): void
    {
        $this->auditor->update(['external_access_level' => 'supervisor']);
        $this->actingAs($this->auditor->fresh());
        Livewire::test(Overview::class)->assertViewHas('totals', fn (array $totals): bool => $totals['waiting'] === 1 && $totals['approved'] === 0);
        DB::table('estatus_ipsa')->where('id', 1)->update(['nombre' => 'Estado anterior QA']);
        $available = DB::table('estatus_ipsa')->insertGetId(['nombre' => ' Disponible ', 'estado_id' => 1]);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => $available]);
        foreach (['Aprobado' => 'approved', 'En revision' => 'in_review', 'Pendiente de revision' => 'ready', 'Rechazado' => 'rejected'] as $status => $key) {
            DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => $status]);
            Livewire::test(Overview::class)->assertViewHas('totals', function (array $totals) use ($key): bool {
                $states = array_diff_key($totals, ['total' => true]);

                return $totals['total'] === 1 && $states[$key] === 1 && array_sum($states) === 1;
            });
        }
        $html = $this->get(route('external-audits.dashboard'))->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html, LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $data = json_decode($xpath->query('//*[@data-audit-chart-data]')->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['totals']['rejected']);
        $this->assertCount(5, $data['states']);
        $this->assertCount(1, $data['branches']);
        $this->assertSame($this->branch, $data['branches'][0]['id']);
        $this->assertSame(2, $xpath->query('//canvas[@data-audit-chart]')->length);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estado_id' => 2]);
        Livewire::test(Overview::class)->assertViewHas('totals', fn (array $totals): bool => array_sum($totals) === 0)
            ->assertSee('Sin contenedores asignados.');
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
                ->assertSee('Auditora mantenimiento')->assertDontSee('Seguimiento de auditoría externa');
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
                ->assertSet('viewMode', 'yard3d')->assertSee('Auditorías')->assertSee('Información')->assertDontSee($foreign['nombre'])
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
                        && $own['actions']['info'] === $own['url']
                        && str_contains($own['url'], '/informacion-auditoria?')
                        && str_contains($own['actions']['history'], '/auditorias?')
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

    public function test_external_information_is_read_only_and_audit_history_preserves_scoped_verdicts(): void
    {
        $returnUrl = '/seguimiento-pyp?patio_id=3&bloque_id=5';
        $historyUrl = route('pyp-audits.history', ['container' => $this->container, 'return_to' => $returnUrl]);
        $this->actingAs($this->auditor)->get(route('pyp-audits.information', ['container' => $this->container, 'return_to' => $returnUrl]))
            ->assertOk()->assertViewIs('containers.show')->assertSee('Datos del equipo')->assertSee('Estados tecnicos')
            ->assertSee(e($returnUrl), false)->assertDontSee('Documentacion y transporte')->assertDontSee('Constancia ingreso')
            ->assertDontSee('Modificar');
        $this->get($historyUrl)->assertOk()->assertViewHas('audits', fn ($rows): bool => $rows->total() === 1)
            ->assertDontSee('>Auditar</a>', false)->assertViewHas('canInspect', false);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->get($historyUrl)->assertOk()->assertViewHas('canInspect', true)
            ->assertDontSee('>Auditar</a>', false)->assertDontSee('Continuar auditoría');
        $previous = route('pyp-audits.show', ['container' => $this->container, 'audit' => 0]);
        $this->get($previous)->assertOk()->assertSee('Aprobacion anterior')->assertDontSee('Guardar dictamen');
        $company = (int) $this->auditor->external_audit_company_id;
        $event = DB::table('container_assignment_events')->insertGetId([
            'container_id' => $this->container, 'ingress_cycle_id' => $this->cycle, 'user_id' => $this->auditor->id,
            'event_type' => 'external_audit_status', 'reason' => 'Revisar sellado', 'before_data' => null,
            'after_data' => json_encode(['audit' => ['estatus_pyp' => 'Rechazado', 'informacion_adicional' => 'Revisar sellado',
                'external_audit_company_id' => $company, 'inspector_name' => 'Inspector del dictamen']], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        $foreign = DB::table('container_assignment_events')->insertGetId([
            'container_id' => $this->container, 'ingress_cycle_id' => $this->cycle, 'user_id' => $this->auditor->id,
            'event_type' => 'external_audit_status', 'reason' => 'Dictamen privado', 'before_data' => null,
            'after_data' => json_encode(['audit' => ['estatus_pyp' => 'Rechazado', 'informacion_adicional' => 'Dictamen privado',
                'external_audit_company_id' => $company + 100000]], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        $old = DB::table('container_assignment_events')->insertGetId([
            'container_id' => $this->container, 'ingress_cycle_id' => null, 'user_id' => $this->auditor->id,
            'event_type' => 'external_audit_status', 'reason' => 'Otro ingreso', 'before_data' => null,
            'after_data' => json_encode(['audit' => ['estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'Otro ingreso',
                'external_audit_company_id' => $company]], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        $this->get($historyUrl)->assertOk()->assertViewHas('audits', fn ($rows): bool => $rows->total() === 2)
            ->assertSee('Inspector del dictamen')->assertDontSee('Dictamen privado')->assertDontSee('Otro ingreso');
        $this->get(route('pyp-audits.history', ['container' => $this->container, 'filters' => ['status' => 'Rechazado'], 'per_page' => 5]))
            ->assertOk()->assertViewHas('audits', fn ($rows): bool => $rows->total() === 1 && $rows->perPage() === 5);
        $this->get(route('pyp-audits.show', ['container' => $this->container, 'audit' => $event]))->assertOk()
            ->assertSee('Revisar sellado')->assertSee('Inspector del dictamen')->assertDontSee('Guardar dictamen');
        foreach ([$foreign, $old] as $hidden) {
            $this->get(route('pyp-audits.show', ['container' => $this->container, 'audit' => $hidden]))->assertNotFound();
        }
        $this->actingAs($this->mechanic)->get($historyUrl)->assertForbidden();
        $this->actingAs($this->auditor)->withSession(['sucursal_activa' => 0])->get($historyUrl)->assertForbidden();
    }

    public function test_audit_start_and_continue_actions_belong_to_inventory_not_the_history(): void
    {
        $container = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($container->bloque_id);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->actingAs($this->auditor);
        foreach (['Pendiente de revision' => 'Auditar', 'En revision' => 'Continuar auditoría'] as $status => $label) {
            DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => $status]);
            $table = Livewire::test(InventoryTable::class)->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
                ->assertSee($label)->assertSee('Auditorías')->assertSee('Información')
                ->assertViewHas('containers', fn (array $rows): bool => $rows[0]->audit_actions['maintenance_label'] === $label
                    && str_contains($rows[0]->audit_actions['maintenance'], '/auditorias/iniciar?'));
            $table->call('setViewMode', 'yard3d')->assertViewHas('yardScene', function (array $scene) use ($label): bool {
                $own = collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers'])->firstWhere('id', $this->container);

                return $own['actions']['maintenance_label'] === $label && str_contains($own['actions']['maintenance'], '/auditorias/iniciar?')
                    && $own['actions']['maintenance_method'] === 'POST';
            });
            $this->get(route('pyp-audits.history', $this->container))->assertOk()
                ->assertDontSee('>Auditar</a>', false)->assertDontSee('Continuar auditoría');
        }
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => $this->oosStatus]);
        Livewire::test(InventoryTable::class)->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertViewHas('containers', fn (array $rows): bool => $rows[0]->audit_actions['maintenance'] === null);
        $this->actingAs($this->mechanic);
        $this->start();
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->actingAs($this->auditor);
        Livewire::test(InventoryTable::class)->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertViewHas('containers', fn (array $rows): bool => $rows[0]->audit_actions['maintenance'] === null);
    }

    public function test_audit_begin_is_explicit_and_drafts_keep_the_review_open_until_finalization(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Pendiente de revision', 'informacion_adicional' => null]);
        $origin = '/seguimiento-pyp?patio_id=3&bloque_id=5';
        $edit = route('pyp-audits.edit', ['container' => $this->container, 'return_to' => $origin]);
        $this->actingAs($this->auditor)->get(route('pyp-audits.information', $this->container))->assertOk();
        $this->get($edit)->assertRedirect(route('pyp-audits.history', ['container' => $this->container, 'return_to' => $origin]));
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Pendiente de revision']);
        $this->post(route('pyp-audits.begin', $this->container), ['audit_version' => $this->auditVersion(), 'return_to' => $origin])->assertRedirect($edit);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'En revision']);
        $version = $this->auditVersion();
        $this->get($edit)->assertOk()->assertSee('Guardar avance')->assertSee('Finalizar auditoría')
            ->assertSee('maxlength="2000"', false)->assertSee('value="Aprobado"', false)->assertSee('value="Rechazado"', false)
            ->assertDontSee('value="En revision"', false)->assertDontSee('value="Pendiente de revision"', false);
        $notes = mb_substr(str_repeat('Inspección de panel sin fugas. ', 70), 0, 2000);
        $this->patch(route('pyp-audits.update', $this->container), ['action' => 'save', 'informacion_adicional' => $notes, 'audit_version' => $version, 'return_to' => $origin])
            ->assertSessionHasNoErrors()->assertRedirect($edit);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'En revision', 'informacion_adicional' => $notes]);
        $saved = $this->auditVersion();
        $this->assertGreaterThan($version, $saved);
        $this->post(route('pyp-audits.begin', $this->container), ['audit_version' => $version])->assertSessionHasNoErrors();
        $this->assertSame($saved, $this->auditVersion());
        $this->patch(route('pyp-audits.update', $this->container), ['action' => 'save', 'informacion_adicional' => 'Avance antiguo', 'audit_version' => $version])
            ->assertSessionHasErrors('audit_version');
        $this->patch(route('pyp-audits.update', $this->container), ['action' => 'finalize', 'informacion_adicional' => $notes, 'audit_version' => $saved])
            ->assertSessionHasErrors('estatus_pyp');
        $this->patch(route('pyp-audits.update', $this->container), ['action' => 'save', 'informacion_adicional' => $notes.'B', 'audit_version' => $saved])
            ->assertSessionHasErrors('informacion_adicional');
        $this->patch(route('pyp-audits.update', $this->container), ['action' => 'finalize', 'estatus_pyp' => 'Aprobado', 'informacion_adicional' => $notes, 'audit_version' => $saved, 'return_to' => $origin])
            ->assertSessionHasNoErrors()->assertRedirect(route('pyp-audits.history', ['container' => $this->container, 'return_to' => $origin]));
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Aprobado', 'informacion_adicional' => $notes]);
        $this->get($edit)->assertRedirect();
        $this->patch(route('pyp-audits.update', $this->container), ['action' => 'save', 'informacion_adicional' => 'Intento sobre cierre', 'audit_version' => $this->auditVersion()])
            ->assertSessionHasErrors('pyp');
        $this->get(route('pyp-audits.history', $this->container))->assertOk()->assertViewHas('audits', fn ($rows): bool => $rows->total() === 1);
    }

    public function test_final_audit_notes_need_thirty_trimmed_characters_but_drafts_can_be_short(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->actingAs($this->auditor);
        foreach (PypAuditService::RESULTS as $result) {
            app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
            $this->get(route('pyp-audits.edit', $this->container))->assertOk()->assertSee('minlength="30"', false);
            $this->patch(route('pyp-audits.update', $this->container), [
                'action' => 'save', 'informacion_adicional' => 'Avance', 'audit_version' => $this->auditVersion(),
            ])->assertSessionHasNoErrors();
            foreach ([str_repeat('a', 29), '   '.str_repeat('a', 29).'   '] as $notes) {
                $version = $this->auditVersion();
                $this->patch(route('pyp-audits.update', $this->container), [
                    'action' => 'finalize', 'estatus_pyp' => $result, 'informacion_adicional' => $notes, 'audit_version' => $version,
                ])->assertSessionHasErrors('informacion_adicional');
                $this->assertSame($version, $this->auditVersion());
                $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'En revision']);
            }
            $this->patch(route('pyp-audits.update', $this->container), [
                'action' => 'finalize', 'estatus_pyp' => $result, 'informacion_adicional' => 'Reparación de panel completada', 'audit_version' => $this->auditVersion(),
                'photos' => $result === 'Rechazado' ? [UploadedFile::fake()->image('defecto.jpg')] : [],
            ])->assertSessionHasNoErrors();
            $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => $result]);
        }
    }

    public function test_audit_begin_rejects_wrong_roles_unavailable_equipment_and_stale_start_requests(): void
    {
        $version = $this->auditVersion();
        $this->actingAs($this->mechanic)->post(route('pyp-audits.begin', $this->container), ['audit_version' => $version])->assertForbidden();
        $this->actingAs($this->auditor)->post(route('pyp-audits.begin', $this->container), ['audit_version' => $version])->assertSessionHasErrors('pyp');
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->post(route('pyp-audits.begin', $this->container), ['audit_version' => $version + 999])->assertSessionHasErrors('pyp');
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Aprobado']);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['external_audit_company_id' => null]);
        $this->post(route('pyp-audits.begin', $this->container), ['audit_version' => $version])->assertSessionHasErrors('pyp');
    }

    public function test_non_available_ipsa_hides_audit_actions_and_blocks_direct_open_start_and_finish(): void
    {
        $container = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($container->bloque_id);
        $pending = DB::table('estatus_ipsa')->whereRaw("UPPER(TRIM(nombre)) = 'PENDIENTE'")->value('id');
        $inactive = (int) DB::table('estatus_ipsa')->where('nombre', 'Disponible')->value('id');
        DB::table('estatus_ipsa')->where('id', $inactive)->update(['estado_id' => 2]);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'En revision']);
        $this->actingAs($this->auditor);
        foreach ([$this->oosStatus, $pending, $inactive] as $status) {
            DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => $status]);
            Livewire::test(InventoryTable::class)->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
                ->assertViewHas('containers', fn (array $rows): bool => ! $rows[0]->ipsa_available && $rows[0]->audit_actions['maintenance'] === null)
                ->assertDontSee('>Auditar</button>', false)->assertDontSee('>Continuar auditoría</button>', false)
                ->call('setViewMode', 'yard3d')->assertViewHas('yardScene', function (array $scene): bool {
                    $own = collect($scene['blocks'])->flatMap(fn (array $block): array => $block['containers'])->firstWhere('id', $this->container);

                    return $own['actions']['maintenance'] === null;
                });
            $this->get(route('pyp-audits.edit', $this->container))->assertRedirect(route('pyp-audits.index'))->assertSessionHasErrors('pyp');
            $this->post(route('pyp-audits.begin', $this->container), ['audit_version' => $this->auditVersion()])->assertSessionHasErrors('pyp');
            $this->patch(route('pyp-audits.update', $this->container), [
                'action' => 'finalize', 'estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'No debe cerrar sin estar disponible.', 'audit_version' => $this->auditVersion(),
            ])->assertSessionHasErrors('pyp');
            $this->get(route('pyp-audits.information', $this->container))->assertOk();
            $this->get(route('pyp-audits.history', $this->container))->assertOk();
            $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'En revision']);
        }
    }

    public function test_audit_availability_uses_the_active_status_name_instead_of_a_fixed_id(): void
    {
        DB::table('estatus_ipsa')->where('id', 1)->update(['nombre' => 'Pendiente QA']);
        $available = DB::table('estatus_ipsa')->insertGetId(['nombre' => 'Disponible', 'estado_id' => 1]);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->actingAs($this->auditor)->post(route('pyp-audits.begin', $this->container), ['audit_version' => $this->auditVersion()])
            ->assertSessionHasErrors('pyp');
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => $available]);
        $this->post(route('pyp-audits.begin', $this->container), ['audit_version' => $this->auditVersion()])->assertSessionHasNoErrors();
        $this->get(route('pyp-audits.edit', $this->container))->assertOk()->assertViewHas('canInspect', true);
    }

    public function test_a_new_verdict_keeps_legacy_results_and_previous_events_unchanged(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $service = app(PypAuditService::class);
        $this->actingAs($this->auditor);
        $service->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
        $legacy = DB::table('container_assignment_events')->where('container_id', $this->container)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(after_data, '$.audit.estatus_pyp')) = 'Aprobado'")->first();
        $this->assertNotNull($legacy);
        $service->update($this->auditor, $this->branch, $this->container, [
            'estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'Unidad revisada sin novedades.', 'audit_version' => $this->auditVersion(),
        ]);
        $this->assertEquals($legacy, DB::table('container_assignment_events')->find($legacy->id));
        $this->get(route('pyp-audits.history', $this->container))->assertOk()
            ->assertViewHas('audits', fn ($rows): bool => $rows->total() === 2)
            ->assertSee('Aprobacion anterior')->assertSee('Unidad revisada sin novedades.');
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

    private function auditVersion(): int
    {
        return (int) DB::table('container_assignment_events')->where('container_id', $this->container)->max('id');
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

    public function test_auditor_can_request_a_first_repair_for_available_equipment_without_faking_a_previous_job(): void
    {
        $okUnit = $this->technicalStatus('OK', 1);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_unidad_id' => $okUnit, 'estatus_ipsa_id' => 1]);
        $create = route('maintenance.create', ['container_id' => $this->container]);
        $this->get($create)->assertNotFound();
        app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
        $notes = 'Se requiere corregir el sellado del panel lateral.';
        $this->actingAs($this->auditor)->patch(route('pyp-audits.update', $this->container), [
            'action' => 'finalize', 'estatus_pyp' => 'Rechazado', 'informacion_adicional' => $notes,
            'audit_version' => $this->auditVersion(), 'photos' => [UploadedFile::fake()->image('hallazgo.jpg')],
        ])->assertSessionHasNoErrors();
        $event = $this->auditVersion();
        $key = 'audit-repair-requested:'.$event;
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->assertDatabaseHas('contenedores', ['id' => $this->container, 'estatus_unidad_id' => $okUnit, 'estatus_ipsa_id' => 1]);
        app(SystemNotifications::class)->auditRepairRequested(Contenedor::findOrFail($this->container), $this->branch);
        $this->assertSame(2, DB::table('system_notifications')->where('event_key', $key)->count());
        foreach ([$this->mechanic, $this->other] as $mechanic) {
            $this->assertSame(1, app(SystemNotifications::class)->inbox($mechanic, $this->branch)->where('n.event_key', $key)->count());
        }
        $this->actingAs($this->mechanic)->get($create)->assertOk()->assertSee($notes)->assertSee('hallazgo.jpg')->assertSee('audit_request_version');
        $block = Bloque::findOrFail(Contenedor::findOrFail($this->container)->bloque_id);
        Livewire::test(InventoryTable::class, ['maintenanceMode' => true])->assertOk()->call('selectPatio', $block->patio_id)->call('selectBlock', $block->id)
            ->assertViewHas('containers', fn (array $rows): bool => (bool) $rows[0]->maintenance_can_start && $rows[0]->maintenance_actions['maintenance_label'] === 'Reparar');
        $photoUrl = route('maintenance.audit-photo', ['container' => $this->container, 'event' => $event, 'photo' => 0]);
        $this->get($photoUrl)->assertOk();
        $pending = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'Pendiente PTI QA', 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_unidad_id' => $pending]);
        $this->get($create)->assertNotFound();
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_unidad_id' => $okUnit]);
        $start = ['container_id' => $this->container, 'audit_request_version' => $event, 'diagnosis' => 'Se confirma el hallazgo técnico de la auditora.',
            'evidence_before' => [UploadedFile::fake()->image('antes-solicitud.jpg')]];
        $this->post(route('maintenance.store'), array_replace($start, ['audit_request_version' => $event + 1]))->assertSessionHasErrors('container_id');
        $notice = app(SystemNotifications::class)->inbox($this->mechanic, $this->branch)->where('n.event_key', $key)->first();
        $this->post(route('notifications.open', $notice->id))->assertRedirect($create);
        $this->post(route('maintenance.store'), $start)->assertSessionHasNoErrors();
        $job = DB::table('maintenance_jobs')->where('container_id', $this->container)->first();
        $this->assertSame($event, (int) $job->source_audit_event_id);
        $this->assertSame((int) $this->mechanic->id, (int) $job->mechanic_id);
        $this->get(route('maintenance.show', $job->id))->assertOk()->assertSee($notes)->assertSee('hallazgo.jpg')->assertViewHas('editable', true);
        $this->patch(route('maintenance.update', $job->id), $this->payload())->assertSessionHasNoErrors();
        $this->get($photoUrl)->assertOk();
        $this->actingAs($this->other)->post(route('maintenance.store'), $start)->assertSessionHasErrors('container_id');
        $this->get($photoUrl)->assertForbidden();
        $this->assertSame(1, DB::table('maintenance_jobs')->where('container_id', $this->container)->count());
        $this->assertSame(0, app(SystemNotifications::class)->inbox($this->other, $this->branch)->where('n.event_key', $key)->count());
        $this->actingAs($this->mechanic)->patch(route('maintenance.update', $job->id), $this->payload([
            'version' => 2, 'action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('despues-solicitud.jpg')],
        ]))->assertSessionHasNoErrors();
        $submitted = DB::table('maintenance_jobs')->find($job->id);
        $this->actingAs($this->admin)->post(route('corrections.confirm', $job->id), ['version' => $submitted->version])->assertSessionHasNoErrors();
        $this->assertSame(1, app(SystemNotifications::class)->inbox($this->auditor, $this->branch)
            ->where('n.event_key', 'audit-correction:job:'.$job->id)->count());
    }

    public function test_pending_unit_or_box_blocks_starting_from_the_menu_and_direct_requests(): void
    {
        $container = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($container->bloque_id);
        foreach (['estatus_unidad_id' => [1, 'Pendiente PTI'], 'estatus_box_id' => [2, 'Pendiente Revision']] as $column => [$type, $name]) {
            $pending = $this->technicalStatus($name, $type);
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
        $pending = $this->technicalStatus('Pendiente PTI', 1);
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

    public function test_mechanic_text_is_limited_to_2000_characters_when_starting_and_updating_a_repair(): void
    {
        $this->postJson('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => str_repeat('a', 2001)])
            ->assertUnprocessable()->assertJsonValidationErrors('diagnosis');
        $this->assertDatabaseMissing('maintenance_jobs', ['container_id' => $this->container]);
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => str_repeat('a', 2000),
            'evidence_before' => [UploadedFile::fake()->image('antes.jpg')]])->assertRedirect()->assertSessionHasNoErrors();
        $job = DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
        $this->patchJson('/mantenimiento/ordenes/'.$job, $this->payload(['diagnosis' => str_repeat('b', 2001)]))
            ->assertUnprocessable()->assertJsonValidationErrors('diagnosis');
        $this->patchJson('/mantenimiento/ordenes/'.$job, $this->payload(['work_performed' => str_repeat('b', 2001)]))
            ->assertUnprocessable()->assertJsonValidationErrors('work_performed');
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['diagnosis' => str_repeat('b', 2000), 'work_performed' => str_repeat('á', 2000)]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $job, 'diagnosis' => str_repeat('b', 2000), 'work_performed' => str_repeat('á', 2000)]);
    }

    public function test_final_repair_diagnosis_and_result_require_thirty_trimmed_characters(): void
    {
        $job = $this->start();
        foreach (['diagnosis', 'work_performed'] as $field) {
            $this->patch('/mantenimiento/ordenes/'.$job, $this->payload([
                'action' => 'submit', $field => '   '.str_repeat('a', 29).'   ',
            ]))->assertSessionHasErrors($field);
        }
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload([
            'action' => 'submit', 'diagnosis' => 'Reparación de panel completada', 'work_performed' => 'Reparación de panel completada',
            'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')],
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $job, 'status' => 'pending_review']);
    }

    public function test_repair_submission_rejects_filler_in_diagnosis_and_result_but_drafts_remain_flexible(): void
    {
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['diagnosis' => str_repeat('r', 40)]))->assertSessionHasNoErrors();
        foreach (['diagnosis', 'work_performed'] as $field) {
            $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2, 'action' => 'submit',
                $field => str_repeat('r', 40), 'evidence_after' => [UploadedFile::fake()->image('final.jpg')]]))->assertSessionHasErrors($field);
            $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'in_progress', 'version' => 2]);
            $this->assertSame(0, DB::table('maintenance_review_requests')->where('job_id', $id)->count());
        }
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload(['version' => 2, 'action' => 'submit',
            'evidence_after' => [UploadedFile::fake()->image('resultado.jpg')]]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'pending_review']);
    }

    public function test_mechanic_forms_return_json_validation_and_redirect_targets_for_background_saves(): void
    {
        $created = $this->postJson(route('maintenance.store'), ['container_id' => $this->container,
            'diagnosis' => 'Se encontro una fuga en el panel lateral.', 'action' => 'save'])->assertOk()->assertJsonStructure(['redirect']);
        $id = (int) DB::table('maintenance_jobs')->where('container_id', $this->container)->value('id');
        $this->assertStringContainsString('/mantenimiento/ordenes/'.$id, $created->json('redirect'));
        $this->patchJson(route('maintenance.update', $id), $this->payload(['diagnosis' => str_repeat('a', 2001)]))
            ->assertUnprocessable()->assertJsonValidationErrors('diagnosis');
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'version' => 1]);
        $this->patchJson(route('maintenance.update', $id), $this->payload())->assertOk()->assertJsonStructure(['redirect']);
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'version' => 2]);
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'returned_for_repair']);
        $round = DB::table('maintenance_corrections')->insertGetId(['job_id' => $id, 'number' => 1, 'requested_by' => $this->auditor->id,
            'reason' => 'Corregir el sellado de la puerta del contenedor.', 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->patchJson(route('corrections.update', $id), ['round_id' => $round, 'version' => 1, 'action' => 'save',
            'work_performed' => str_repeat('a', 2001)])->assertUnprocessable()->assertJsonValidationErrors('work_performed');
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round, 'version' => 1]);
        $this->patchJson(route('corrections.update', $id), ['round_id' => $round, 'version' => 1, 'action' => 'save',
            'work_performed' => 'Se corrigio el sellado de la puerta.'])->assertOk()->assertJsonStructure(['redirect']);
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round, 'version' => 2]);
    }

    public function test_auditor_background_saves_return_field_errors_and_do_not_redirect_failed_context_checks(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
        $this->actingAs($this->auditor);
        $version = $this->auditVersion();
        $this->patchJson(route('pyp-audits.update', $this->container), ['action' => 'finalize', 'estatus_pyp' => 'Aprobado',
            'informacion_adicional' => str_repeat('r', 40), 'audit_version' => $version])->assertUnprocessable()->assertJsonValidationErrors('informacion_adicional');
        $this->assertSame($version, $this->auditVersion());
        $this->patchJson(route('pyp-audits.update', $this->container), ['action' => 'save',
            'informacion_adicional' => 'Se reviso el contenedor sin encontrar defectos.', 'audit_version' => $version])->assertOk()->assertJsonStructure(['redirect']);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'En revision',
            'informacion_adicional' => 'Se reviso el contenedor sin encontrar defectos.']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => $this->oosStatus]);
        $this->patchJson(route('pyp-audits.update', $this->container), ['action' => 'finalize', 'estatus_pyp' => 'Aprobado',
            'informacion_adicional' => 'Se reviso el contenedor sin encontrar defectos.', 'audit_version' => $this->auditVersion()])
            ->assertUnprocessable()->assertJsonValidationErrors('pyp');
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'En revision']);
    }

    public function test_correction_submission_rejects_filler_without_changing_the_round_or_original_job(): void
    {
        $id = $this->start();
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'returned_for_repair']);
        $round = DB::table('maintenance_corrections')->insertGetId(['job_id' => $id, 'number' => 1, 'requested_by' => $this->auditor->id,
            'reason' => 'Corregir sellado de la puerta del contenedor.', 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $data = ['round_id' => $round, 'version' => 1, 'action' => 'submit', 'repair_service_id' => $this->service,
            'diagnosis' => 'Se encontro una fuga en el sello de la puerta.', 'work_performed' => 'Se cambio el sello y se verifico sin fugas.'];
        foreach (['diagnosis', 'work_performed'] as $field) {
            $this->patch(route('corrections.update', $id), array_replace($data, [$field => '12345678901234567890123456789012345',
                'photos_before' => [UploadedFile::fake()->image('antes.jpg')], 'photos' => [UploadedFile::fake()->image('despues.jpg', 20, 20)]]))->assertSessionHasErrors($field);
            $this->assertDatabaseHas('maintenance_corrections', ['id' => $round, 'status' => 'draft', 'version' => 1, 'diagnosis' => null]);
            $this->assertDatabaseHas('maintenance_jobs', ['id' => $id, 'status' => 'returned_for_repair']);
        }
        $this->patch(route('corrections.update', $id), array_replace($data, ['action' => 'save', 'diagnosis' => str_repeat('r', 40)]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round, 'version' => 2, 'diagnosis' => str_repeat('r', 40)]);
        $this->patch(route('corrections.update', $id), array_replace($data, ['version' => 2,
            'photos_before' => [UploadedFile::fake()->image('antes.jpg')], 'photos' => [UploadedFile::fake()->image('despues.jpg', 20, 20)]]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round, 'status' => 'submitted']);
    }

    public function test_audit_finalization_rejects_filler_while_drafts_and_technical_observations_are_allowed(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
        $this->actingAs($this->auditor);
        $this->patch(route('pyp-audits.update', $this->container), ['action' => 'save', 'informacion_adicional' => str_repeat('r', 40),
            'audit_version' => $this->auditVersion()])->assertSessionHasNoErrors();
        $version = $this->auditVersion();
        $this->patch(route('pyp-audits.update', $this->container), ['action' => 'finalize', 'estatus_pyp' => 'Aprobado',
            'informacion_adicional' => str_repeat('r', 40), 'audit_version' => $version])->assertSessionHasErrors('informacion_adicional');
        $this->assertSame($version, $this->auditVersion());
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'En revision']);
        $this->patch(route('pyp-audits.update', $this->container), ['action' => 'finalize', 'estatus_pyp' => 'Aprobado',
            'informacion_adicional' => 'PTI OK: presion 125 PSI; sin fugas en valvula #4.', 'audit_version' => $version])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Aprobado']);
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

    public function test_correction_estimates_and_result_costs_keep_their_own_prices_and_exchange_rate(): void
    {
        DB::table('repair_services')->where('id', $this->service)->update(['reference_cost' => '75.00', 'currency' => 'GTQ']);
        DB::table('repair_parts')->where('id', $this->part)->update(['reference_cost' => '10.00', 'currency' => 'GTQ']);
        $id = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$id, $this->payload())->assertSessionHasNoErrors();
        $original = DB::table('maintenance_jobs')->find($id);
        $originalMaterials = DB::table('maintenance_materials')->where('job_id', $id)->get();
        $originalCost = app(MaintenanceCost::class)->summary($original, $originalMaterials);
        DB::table('maintenance_jobs')->where('id', $id)->update(['status' => 'returned_for_repair']);
        $round = DB::table('maintenance_corrections')->insertGetId(['job_id' => $id, 'number' => 1, 'requested_by' => $this->auditor->id,
            'reason' => 'Corregir nuevamente el sellado de la puerta.', 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('repair_services')->where('id', $this->service)->update(['reference_cost' => '100.00']);
        DB::table('repair_parts')->where('id', $this->part)->update(['reference_cost' => '2.00', 'currency' => 'USD']);
        $response = $this->get(route('corrections.show', $id))->assertOk()->assertSee('Estimación')->assertSee('Costo calculado')->assertSee('Corrección 1');
        $data = ['round_id' => $round, 'version' => 1, 'action' => 'save', 'repair_service_id' => $this->service,
            'materials' => [['part_id' => $this->part, 'quantity' => '1.500', 'unit_cost' => '0.01']], 'exchange_quote' => 'tampered'];
        $this->patch(route('corrections.update', $id), $data)->assertSessionHasErrors('exchange_quote');
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round, 'version' => 1]);
        $data['exchange_quote'] = $response->viewData('exchangeQuote')['token'];
        $this->patch(route('corrections.update', $id), $data)->assertSessionHasNoErrors();
        $saved = DB::table('maintenance_corrections')->find($round);
        $snapshot = json_decode($saved->repair_service_snapshot, true);
        $this->assertSame('100.00', $snapshot['unit_cost']);
        $this->assertSame('7.650000', $snapshot['exchange']['usd_gtq_rate']);
        $this->assertSame('2.00', json_decode($saved->materials, true)[0]['unit_cost']);
        DB::table('repair_services')->where('id', $this->service)->update(['reference_cost' => '999.00']);
        DB::table('repair_parts')->where('id', $this->part)->update(['reference_cost' => '888.00', 'currency' => 'GTQ']);
        $editor = $this->get(route('corrections.show', $id))->assertOk()->assertSee('data-exchange-rate="7.650000"', false);
        $this->assertSame('100.00', $editor->viewData('repairServices')->firstWhere('id', $this->service)->reference_cost);
        $this->assertSame('2.00', $editor->viewData('parts')->firstWhere('id', $this->part)->reference_cost);
        $this->patch(route('corrections.update', $id), array_replace($data, ['version' => 2]))->assertSessionHasNoErrors();
        $this->assertSame($saved->repair_service_snapshot, DB::table('maintenance_corrections')->find($round)->repair_service_snapshot);
        DB::table('maintenance_corrections')->where('id', $round)->update(['status' => 'submitted']);
        $this->get(route('corrections.detail', ['job' => $id, 'intervention' => $round]))->assertOk()->assertSee('Costo calculado')->assertSee('Q 122.95');
        $secondRound = DB::table('maintenance_corrections')->insertGetId(['job_id' => $id, 'number' => 2, 'requested_by' => $this->auditor->id,
            'reason' => 'Nueva solicitud de correccion independiente.', 'status' => 'submitted', 'version' => 1,
            'repair_service_snapshot' => json_encode(['name' => 'Segunda correccion', 'unit_cost' => '5.00', 'currency' => 'GTQ'], JSON_THROW_ON_ERROR),
            'materials' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('corrections.detail', ['job' => $id, 'intervention' => $secondRound]))->assertOk()->assertSee('Corrección 2')->assertSee('Q 5.00')->assertDontSee('Q 122.95');
        $this->get(route('corrections.detail', $id))->assertOk()->assertSee($originalCost['total']);
        $this->assertSame($originalCost, app(MaintenanceCost::class)->summary(DB::table('maintenance_jobs')->find($id), DB::table('maintenance_materials')->where('job_id', $id)->get()));
    }

    public function test_calculated_costs_distinguish_zero_prices_and_incomplete_currency_conversion(): void
    {
        $calculator = app(MaintenanceCost::class);
        $job = (object) ['service_unit_cost' => '0.00', 'service_currency' => 'GTQ', 'usd_gtq_rate' => null];
        $this->assertSame(['labor' => 'Q 0.00', 'materials' => 'Q 0.00', 'total' => 'Q 0.00', 'incomplete' => false], $calculator->summary($job, []));
        $materials = [(object) ['unit_cost' => '2.00', 'quantity' => '1.500', 'currency' => 'USD']];
        $this->assertTrue($calculator->summary($job, $materials)['incomplete']);
        $job->usd_gtq_rate = '7.650000';
        $this->assertSame('Q 22.95', $calculator->summary($job, $materials)['total']);
        $job->service_unit_cost = null;
        $this->assertSame('Parcial: Q 22.95', $calculator->summary($job, $materials)['total']);
        $this->assertSame('Pendiente', $calculator->summary($job, $materials)['labor']);
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
        $this->get('/mantenimiento/ordenes')->assertOk()->assertDontSee('Nuevo trabajo')->assertDontSee('Seguimiento de auditoría externa');
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
        $service->auditAvailable($container, $this->branch, (object) ['estatus_ipsa_id' => $this->oosStatus], ['estatus_ipsa_id' => 1], $this->admin);
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
        $this->actingAs($this->auditor)->get(route('notifications.index'))->assertOk()->assertSee('Contenedor disponible');
        $this->auditor->update(['external_audit_company_id' => null]);
        $this->get(route('notifications.index'))->assertForbidden();
    }

    public function test_external_approval_notifies_only_the_original_mechanic_and_rolls_back_with_the_verdict(): void
    {
        $job = $this->start();
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'completed']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
        $data = ['action' => 'finalize', 'estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'Se verifico el equipo y no presenta defectos.',
            'audit_version' => $this->auditVersion(), 'maintenance_version' => DB::table('maintenance_jobs')->find($job)->version];
        $notifications = app(SystemNotifications::class);
        try {
            DB::transaction(function () use ($data): void {
                app(PypAuditService::class)->update($this->auditor, $this->branch, $this->container, $data);
                throw new \RuntimeException('Rollback de prueba');
            });
            $this->fail('La transaccion debe revertirse.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback de prueba', $exception->getMessage());
        }
        $this->assertSame(0, $notifications->inbox($this->mechanic, $this->branch)->where('n.event_key', 'like', 'audit-approved:%')->count());
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'En revision']);
        $this->actingAs($this->auditor)->patch(route('pyp-audits.update', $this->container), $data)->assertSessionHasNoErrors();
        $notice = $notifications->inbox($this->mechanic, $this->branch)->where('n.event_key', 'like', 'audit-approved:%')->first();
        $this->assertNotNull($notice);
        $this->assertSame('Reparación aprobada por la auditora', $notice->title);
        $this->assertStringContainsString($data['informacion_adicional'], $notice->body);
        $details = json_decode($notice->details, true);
        $this->assertSame($this->auditor->username, $details['actor']);
        $this->assertNull($details['correction_id']);
        $this->assertSame(0, $notifications->inbox($this->other, $this->branch)->where('n.event_key', 'like', 'audit-approved:%')->count());
        $this->actingAs($this->other)->post(route('notifications.open', $notice->id))->assertNotFound();
        $this->actingAs($this->mechanic)->post(route('notifications.open', $notice->id))->assertRedirect(route('corrections.detail', $job));
        DB::table('maintenance_corrections')->insert(['job_id' => $job, 'number' => 1, 'requested_by' => $this->auditor->id,
            'reason' => 'Solicitud posterior a la aprobacion original.', 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('notifications.open', $notice->id))->assertRedirect(route('corrections.detail', $job));
        $this->actingAs($this->auditor)->patch(route('pyp-audits.update', $this->container), $data)->assertSessionHasErrors('pyp');
        $this->assertSame(1, $notifications->inbox($this->mechanic, $this->branch)->where('n.event_key', 'like', 'audit-approved:%')->count());
    }

    public function test_external_rejection_notifies_the_responsible_mechanic_and_keeps_the_requested_round_link(): void
    {
        $job = $this->start();
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'completed']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
        $this->actingAs($this->auditor)->patch(route('pyp-audits.update', $this->container), ['action' => 'finalize', 'estatus_pyp' => 'Rechazado',
            'informacion_adicional' => 'Se requiere corregir el sello de la puerta.', 'audit_version' => $this->auditVersion(),
            'maintenance_version' => DB::table('maintenance_jobs')->find($job)->version, 'photos' => [UploadedFile::fake()->image('defecto.jpg')]])->assertSessionHasNoErrors();
        $round = DB::table('maintenance_corrections')->where('job_id', $job)->first();
        $notifications = app(SystemNotifications::class);
        $notice = $notifications->inbox($this->mechanic, $this->branch)->where('n.event_key', 'correction-requested:'.$round->id)->first();
        $this->assertNotNull($notice);
        $this->assertSame('La auditora solicitó una corrección', $notice->title);
        $details = json_decode($notice->details, true);
        $this->assertSame((int) $round->id, $details['correction_id']);
        $this->assertSame($this->auditor->username, $details['actor']);
        $this->assertSame(0, $notifications->inbox($this->other, $this->branch)->where('n.event_key', 'like', 'correction-requested:%')->count());
        $this->actingAs($this->mechanic)->post(route('notifications.open', $notice->id))->assertRedirect(route('corrections.show', $job));
        $this->get(route('corrections.show', $job))->assertOk()->assertSee('Solicitud de corrección')->assertSee('defecto.jpg');
        DB::table('maintenance_corrections')->where('id', $round->id)->update(['status' => 'submitted']);
        $this->post(route('notifications.open', $notice->id))->assertRedirect(route('corrections.detail', ['job' => $job, 'intervention' => $round->id]));
    }

    public function test_approval_without_a_mechanic_job_does_not_send_any_mechanic_an_approval_notice(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
        $this->actingAs($this->auditor)->patch(route('pyp-audits.update', $this->container), ['action' => 'finalize', 'estatus_pyp' => 'Aprobado',
            'informacion_adicional' => 'El contenedor no presenta daños ni requiere reparacion.', 'audit_version' => $this->auditVersion()])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('system_notifications')->where('event_key', 'like', 'audit-approved:%')->where('container_id', $this->container)->count());
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Aprobado']);
    }

    public function test_correction_notice_starts_or_continues_audit_and_opens_its_verdict_after_completion(): void
    {
        $job = $this->start();
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'completed']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Pendiente de revision']);
        $round = DB::table('maintenance_corrections')->insertGetId(['job_id' => $job, 'number' => 1, 'requested_by' => $this->auditor->id,
            'reason' => 'Revisar nuevamente el sello de la puerta.', 'status' => 'submitted', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $notifications = app(SystemNotifications::class);
        $notifications->correctionSubmitted(Contenedor::findOrFail($this->container), $this->branch, $round, $this->mechanic);
        $notice = $notifications->inbox($this->auditor, $this->branch)->where('n.event_key', 'audit-correction:'.$round)->first();
        $origin = '/notificaciones?category=audits';
        $editor = route('pyp-audits.edit', ['container' => $this->container, 'return_to' => $origin]);
        $this->actingAs($this->auditor)->post(route('notifications.open', $notice->id), ['return_to' => $origin])->assertRedirect($editor);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'En revision']);
        $this->assertDatabaseMissing('system_notifications', ['id' => $notice->id, 'read_at' => null]);
        $revision = $this->auditVersion();
        $this->post(route('notifications.open', $notice->id), ['return_to' => $origin])->assertRedirect($editor);
        $this->assertSame($revision, $this->auditVersion());
        $this->get($editor)->assertOk()->assertViewHas('maintenanceCorrectionId', $round)->assertSee('Ver corrección');
        $this->patch(route('pyp-audits.update', $this->container), ['action' => 'finalize', 'estatus_pyp' => 'Aprobado',
            'informacion_adicional' => 'Se verifico el sello y no presenta fugas.', 'audit_version' => $revision,
            'maintenance_version' => DB::table('maintenance_jobs')->find($job)->version])->assertSessionHasNoErrors();
        $verdict = $this->auditVersion();
        $event = json_decode(DB::table('container_assignment_events')->find($verdict)->after_data, true);
        $this->assertSame($round, $event['audit']['maintenance_correction_id']);
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round, 'status' => 'approved']);
        $approval = $notifications->inbox($this->mechanic, $this->branch)->where('n.event_key', 'audit-approved:'.$verdict)->first();
        $this->assertNotNull($approval);
        $this->assertSame('Corrección aprobada por la auditora', $approval->title);
        $approvalDetails = json_decode($approval->details, true);
        $this->assertSame($round, $approvalDetails['correction_id']);
        $notifications->maintenanceResult($job, $approval->title, 'Reintento de entrega', 'audit-approved', $verdict, $approvalDetails);
        $this->assertSame(1, $notifications->inbox($this->mechanic, $this->branch)->where('n.event_key', 'audit-approved:'.$verdict)->count());
        $this->actingAs($this->mechanic)->post(route('notifications.open', $approval->id))->assertRedirect(route('corrections.detail', ['job' => $job, 'intervention' => $round]));
        $this->actingAs($this->auditor);
        $destination = route('pyp-audits.show', ['container' => $this->container, 'audit' => $verdict, 'return_to' => $origin]);
        $this->post(route('notifications.open', $notice->id), ['return_to' => $origin])->assertRedirect($destination);
        $this->assertSame($verdict, $this->auditVersion());
        DB::table('maintenance_corrections')->insert(['job_id' => $job, 'number' => 2, 'requested_by' => $this->auditor->id,
            'reason' => 'Solicitud de otra revision de la puerta.', 'status' => 'submitted', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Pendiente de revision']);
        $this->post(route('notifications.open', $notice->id), ['return_to' => $origin])->assertRedirect($destination);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Pendiente de revision']);
    }

    public function test_correction_notice_cannot_start_unavailable_work_or_be_opened_by_another_recipient(): void
    {
        $job = $this->start();
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'completed']);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Pendiente de revision']);
        $round = DB::table('maintenance_corrections')->insertGetId(['job_id' => $job, 'number' => 1, 'requested_by' => $this->auditor->id,
            'reason' => 'Revisar nuevamente el sello de la puerta.', 'status' => 'submitted', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $notifications = app(SystemNotifications::class);
        $notifications->correctionSubmitted(Contenedor::findOrFail($this->container), $this->branch, $round, $this->mechanic);
        $notice = $notifications->inbox($this->auditor, $this->branch)->where('n.event_key', 'audit-correction:'.$round)->first();
        $this->actingAs($this->other)->post(route('notifications.open', $notice->id))->assertNotFound();
        $revision = $this->auditVersion();
        $this->actingAs($this->auditor)->post(route('notifications.open', $notice->id))
            ->assertRedirect(route('pyp-audits.history', $this->container))->assertSessionHasErrors('pyp');
        $this->assertSame($revision, $this->auditVersion());
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Pendiente de revision']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        DB::table('maintenance_corrections')->where('id', $round)->update(['status' => 'approved']);
        $this->post(route('notifications.open', $notice->id))->assertRedirect(route('pyp-audits.history', $this->container));
        $this->assertSame($revision, $this->auditVersion());
    }

    public function test_technical_repair_notice_opens_review_but_cannot_start_a_later_correction(): void
    {
        $job = $this->start();
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'completed']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Pendiente de revision']);
        $notifications = app(SystemNotifications::class);
        $notifications->requestedRepairConfirmed(Contenedor::findOrFail($this->container), $this->branch, $job, $this->admin);
        $notice = $notifications->inbox($this->auditor, $this->branch)->where('n.event_key', 'audit-correction:job:'.$job)->first();
        $this->actingAs($this->auditor)->post(route('notifications.open', $notice->id))->assertRedirect(route('pyp-audits.edit', $this->container));
        $revision = $this->auditVersion();
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Pendiente de revision']);
        DB::table('maintenance_corrections')->insert(['job_id' => $job, 'number' => 1, 'requested_by' => $this->auditor->id,
            'reason' => 'Solicitud posterior de correccion tecnica.', 'status' => 'submitted', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('notifications.open', $notice->id))->assertRedirect(route('pyp-audits.history', $this->container));
        $this->assertSame($revision, $this->auditVersion());
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'Pendiente de revision']);
    }

    public function test_external_notices_only_announce_release_after_damage_and_hide_obsolete_assignment_notices(): void
    {
        $job = $this->start();
        $this->patch('/mantenimiento/ordenes/'.$job, $this->payload(['action' => 'submit', 'evidence_after' => [UploadedFile::fake()->image('final.jpg')]]))->assertSessionHasNoErrors();
        $version = (int) DB::table('maintenance_jobs')->where('id', $job)->value('version');
        $this->actingAs($this->admin)->post(route('notifications.confirm', $job), ['version' => $version])->assertSessionHasNoErrors();
        $notifications = app(SystemNotifications::class);
        $this->assertSame(0, $notifications->inbox($this->auditor, $this->branch)->count());
        $container = Contenedor::findOrFail($this->container);
        $before = (object) $container->toArray();
        $after = array_replace($container->toArray(), ['estatus_ipsa_id' => 1]);
        $notifications->containerStatuses($container, $this->branch, $before, $after, $this->admin);
        $notice = $notifications->inbox($this->auditor, $this->branch)->first();
        $this->assertSame('Contenedor disponible', $notice->title);
        $this->assertStringStartsWith('audit-available:', $notice->event_key);
        $this->assertSame($this->admin->username, json_decode($notice->details, true)['actor']);
        $this->assertSame(0, $notifications->inbox($this->other, $this->branch)->where('n.audience', 'auditor')->count());
        $notifications->auditAvailable($container, $this->branch, (object) $after, $after, $this->admin);
        $pending = DB::table('estatus_ipsa')->insertGetId(['nombre' => 'Pendiente QA', 'estado_id' => 1]);
        $notifications->auditAvailable($container, $this->branch, (object) ['estatus_ipsa_id' => $pending], $after, $this->admin);
        $notifications->auditAvailable($container, $this->branch, $before, ['estatus_ipsa_id' => $this->oosStatus], $this->admin);
        $this->assertSame(1, $notifications->inbox($this->auditor, $this->branch)->count());
        $legacy = (array) DB::table('system_notifications')->find($notice->id);
        unset($legacy['id']);
        foreach (['Reparación lista para auditoría', 'Contenedor asignado a tu auditora'] as $title) {
            $id = DB::table('system_notifications')->insertGetId(array_replace($legacy, ['event_key' => 'audit-assigned:'.uniqid(), 'title' => $title]));
            $this->assertDatabaseHas('system_notifications', ['id' => $id, 'title' => $title]);
            $this->actingAs($this->auditor)->post(route('notifications.open', $id))->assertNotFound();
        }
        $this->assertSame(1, $notifications->inbox($this->auditor, $this->branch)->count());
        Livewire::test(NotificationBell::class)->assertSee('Contenedor disponible')
            ->assertDontSee('Reparación lista para auditoría')->assertDontSee('Contenedor asignado a tu auditora');
        $this->auditor->update(['external_audit_company_id' => null]);
        $notifications->auditAvailable($container, $this->branch, $before, $after, $this->admin);
        $this->assertSame(1, DB::table('system_notifications')->where('user_id', $this->auditor->id)->where('event_key', 'like', 'audit-available:%')->count());
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
        $boxOk = $this->technicalStatus('OK QA', 2);
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
        $boxOk = $this->technicalStatus('OK QA', 2);
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
        $damage = $this->technicalStatus('DMG QA', 1);
        $unitOk = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA', 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        $boxOk = $this->technicalStatus('OK QA', 2);
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
        $boxOk = $this->technicalStatus('OK QA', 2);
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
        $boxOk = DB::table('contenedores')->where('id', $this->container)->value('estatus_box_id');
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
        $this->get($detail)->assertOk()->assertDontSee('aria-controls="repairInterventionsMenu"', false)
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
        $verdict = ['estatus_pyp' => 'Rechazado', 'informacion_adicional' => 'Corregir el sellado de la puerta.', 'maintenance_version' => $confirmed->version,
            'photos' => [UploadedFile::fake()->image('defecto-auditora.jpg')]];
        app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
        $verdict['audit_version'] = $this->auditVersion();
        $this->actingAs($this->auditor)->patch(route('pyp-audits.update', $this->container), $verdict)->assertSessionHasNoErrors();
        $round = DB::table('maintenance_corrections')->where('job_id', $job)->first();
        $this->assertSame('draft', $round->status);
        $auditPhotos = json_decode($round->audit_photos, true);
        $this->assertCount(1, $auditPhotos);
        Storage::disk('local')->assertExists($auditPhotos[0]['path']);
        $this->assertDatabaseHas('maintenance_jobs', ['id' => $job, 'status' => 'returned_for_repair']);
        $this->get(route('corrections.photo', ['job' => $job, 'round' => $round->id, 'photo' => 0]))->assertNotFound();
        $data = ['round_id' => $round->id, 'version' => 1, 'action' => 'submit', 'work_performed' => 'Se reemplazó y selló el empaque.',
            'diagnosis' => 'Se encontro una fuga en el empaque de la puerta.', 'repair_service_id' => $this->service];
        $this->actingAs($this->mechanic)->get(route('corrections.show', $job))->assertOk()->assertViewIs('maintenance.corrections')
            ->assertSee('Corregir reparación')->assertSee('Solicitud de corrección')->assertSee($this->auditor->username)
            ->assertSee($verdict['informacion_adicional'])->assertSee('Trabajo de corrección realizado')
            ->assertSee('defecto-auditora.jpg')
            ->assertSee('data-correction-photo-previews', false)->assertSee('Guardar borrador')->assertSee('Enviar a auditoría')
            ->assertSee('data-maintenance-panel="0"', false)->assertSee('data-maintenance-panel="1"', false)->assertSee('data-maintenance-panel="2"', false)
            ->assertSee('name="photos_before[]"', false)->assertSee('Fotos de evidencia final')->assertSee('name="repair_service_id"', false)
            ->assertDontSee('Historial de correcciones')->assertDontSee('Diagnóstico, trabajo y evidencias')
            ->assertDontSee($original->diagnosis)->assertDontSee($original->work_performed);
        $this->actingAs($this->other)->patch(route('corrections.update', $job), $data)->assertForbidden();
        $auditPhotoUrl = route('corrections.audit-photo', ['job' => $job, 'round' => $round->id, 'photo' => 0]);
        $this->get($auditPhotoUrl)->assertForbidden();
        $this->actingAs($this->mechanic)->get($auditPhotoUrl)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->mechanic)->patch(route('corrections.update', $job), array_replace($data, ['diagnosis' => 'corto']))->assertSessionHasErrors('diagnosis');
        $this->patch(route('corrections.update', $job), array_replace($data, ['diagnosis' => str_repeat('a', 2001)]))->assertSessionHasErrors('diagnosis');
        $this->patch(route('corrections.update', $job), array_replace($data, ['work_performed' => str_repeat('a', 2001)]))->assertSessionHasErrors('work_performed');
        $this->patch(route('corrections.update', $job), array_replace($data, ['work_performed' => '   '.str_repeat('a', 29).'   ',
            'photos_before' => [UploadedFile::fake()->image('evaluacion.jpg')], 'photos' => [UploadedFile::fake()->image('resultado.jpg', 30, 30)]]))->assertSessionHasErrors('work');
        $this->patch(route('corrections.update', $job), array_replace($data, ['repair_service_id' => null]))->assertSessionHasErrors('repair_service_id');
        $this->patch(route('corrections.update', $job), $data)->assertSessionHasErrors('photos_before');
        $this->patch(route('corrections.update', $job), array_replace($data, ['action' => 'save', 'active_step' => 0, 'work_performed' => '',
            'photos_before' => [UploadedFile::fake()->image('correccion-inicial.jpg')]]))->assertSessionHasNoErrors();
        $savedRound = DB::table('maintenance_corrections')->find($round->id);
        $this->assertSame('draft', $savedRound->status);
        $this->assertSame($data['diagnosis'], $savedRound->diagnosis);
        $this->assertCount(1, json_decode($savedRound->photos_before, true));
        $this->assertSame('returned_for_repair', DB::table('maintenance_jobs')->find($job)->status);
        $this->get(route('corrections.show', ['job' => $job, 'step' => 1]))->assertOk()->assertSee('data-initial-step="1"', false)->assertSee($data['diagnosis']);
        $this->patch(route('corrections.update', $job), $data)->assertSessionHasErrors('work');
        $data['version'] = 2;
        $this->patch(route('corrections.update', $job), $data)->assertSessionHasErrors('work');
        $this->patch(route('corrections.update', $job), $data + ['photos' => [UploadedFile::fake()->image('correccion.jpg', 30, 30)]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round->id, 'status' => 'submitted', 'version' => 3]);
        $this->get(route('corrections.detail', ['job' => $job, 'intervention' => $round->id]))->assertOk()
            ->assertSee($data['diagnosis'])->assertSee('correccion-inicial.jpg')->assertSee('correccion.jpg');
        $this->get(route('corrections.photo', ['job' => $job, 'round' => $round->id, 'photo' => 0, 'stage' => 'before']))->assertOk();
        $this->get(route('corrections.photo', ['job' => $job, 'round' => $round->id, 'photo' => 0, 'stage' => 'invalid']))->assertNotFound();
        $notice = app(SystemNotifications::class)->inbox($this->auditor, $this->branch)->where('n.event_key', 'audit-correction:'.$round->id)->first();
        $this->assertNotNull($notice);
        $this->assertSame('Corrección lista para revisión', $notice->title);
        $this->assertSame($this->mechanic->username, json_decode($notice->details, true)['actor']);
        app(SystemNotifications::class)->correctionSubmitted(Contenedor::findOrFail($this->container), $this->branch, (int) $round->id, $this->mechanic);
        $this->assertSame(1, app(SystemNotifications::class)->inbox($this->auditor, $this->branch)->where('n.event_key', 'audit-correction:'.$round->id)->count());
        $this->patch(route('corrections.update', $job), $data)->assertStatus(409);
        $this->assertSame($original->diagnosis, DB::table('maintenance_jobs')->find($job)->diagnosis);
        $this->assertSame($original->work_performed, DB::table('maintenance_jobs')->find($job)->work_performed);
        $this->assertSame($originalMaterials, DB::table('maintenance_materials')->where('job_id', $job)->get()->toJson());
        $this->assertSame($originalEvidence, DB::table('maintenance_evidence')->where('job_id', $job)->get()->toJson());
        $this->actingAs($this->auditor)->patch(route('pyp-audits.update', $this->container), $verdict)->assertSessionHasErrors('pyp');
        $verdict['maintenance_version'] = DB::table('maintenance_jobs')->find($job)->version;
        app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
        $verdict['audit_version'] = $this->auditVersion();
        $verdict['photos'] = [UploadedFile::fake()->image('segundo-defecto.jpg', 40, 40)];
        $this->patch(route('pyp-audits.update', $this->container), $verdict)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('maintenance_corrections', ['id' => $round->id, 'status' => 'rejected', 'work_performed' => $data['work_performed']]);
        $this->assertDatabaseHas('maintenance_corrections', ['job_id' => $job, 'number' => 2, 'status' => 'draft']);
        $this->post(route('notifications.open', $notice->id))->assertRedirect(route('pyp-audits.show', ['container' => $this->container, 'audit' => $this->auditVersion()]));
        $this->assertSame(1, DB::table('maintenance_jobs')->where('container_id', $this->container)->count());
    }

    public function test_audit_photos_require_evidence_for_rejection_and_preserve_each_verdict(): void
    {
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $this->actingAs($this->auditor);
        app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $this->auditVersion());
        $url = route('pyp-audits.update', $this->container);
        $data = ['action' => 'finalize', 'estatus_pyp' => 'Rechazado', 'informacion_adicional' => 'Se encontro una fuga en la puerta.', 'audit_version' => $this->auditVersion()];
        $this->patch($url, $data)->assertSessionHasErrors('photos');
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $this->container, 'estatus_pyp' => 'En revision']);
        $this->patch($url, $data + ['photos' => [UploadedFile::fake()->create('invalida.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('photos.0');
        $this->patch($url, $data + ['photos' => [UploadedFile::fake()->image('grande.jpg')->size(2049)]])->assertSessionHasErrors('photos.0');
        $this->patch($url, $data + ['photos' => array_map(fn ($index) => UploadedFile::fake()->image('foto-'.$index.'.jpg'), range(1, 6))])->assertSessionHasErrors('photos');
        $this->patch($url, array_replace($data, ['action' => 'save', 'informacion_adicional' => '', 'photos' => [UploadedFile::fake()->image('fuga.jpg')]]))->assertSessionHasNoErrors();
        $draftVersion = $this->auditVersion();
        $draftUrl = route('pyp-audits.photo', ['container' => $this->container, 'audit' => $draftVersion, 'photo' => 0]);
        $this->get($draftUrl)->assertOk();
        $this->get(route('pyp-audits.edit', $this->container))->assertOk()->assertSee('fuga.jpg')->assertSee('enctype="multipart/form-data"', false);
        $this->patch($url, array_replace($data, ['audit_version' => $draftVersion, 'remove_photos' => [0]]))->assertSessionHasErrors('photos');
        $this->assertSame($draftVersion, $this->auditVersion());
        $this->patch($url, array_replace($data, ['audit_version' => $draftVersion]))->assertSessionHasNoErrors();
        $verdictId = $this->auditVersion();
        $verdict = DB::table('container_assignment_events')->find($verdictId);
        $snapshot = json_decode($verdict->after_data, true)['audit']['photos'];
        $photoUrl = route('pyp-audits.photo', ['container' => $this->container, 'audit' => $verdictId, 'photo' => 0]);
        $this->get($photoUrl)->assertOk();
        $this->get($draftUrl)->assertNotFound();
        $this->get(route('pyp-audits.show', ['container' => $this->container, 'audit' => $verdictId]))->assertOk()->assertSee('fuga.jpg');
        $this->actingAs($this->mechanic)->get($photoUrl)->assertForbidden();
        $this->actingAs($this->auditor);
        app(PypAuditService::class)->begin($this->auditor, $this->branch, $this->container, $verdictId);
        $this->get(route('pyp-audits.edit', $this->container))->assertOk()->assertDontSee('fuga.jpg');
        $this->get($photoUrl)->assertOk();
        $this->patch($url, array_replace($data, ['estatus_pyp' => 'Aprobado', 'audit_version' => $this->auditVersion()]))->assertSessionHasNoErrors();
        $this->assertSame($snapshot, json_decode(DB::table('container_assignment_events')->find($verdictId)->after_data, true)['audit']['photos']);
        Storage::disk('local')->assertExists($snapshot[0]['path']);
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
        $this->actingAs($this->admin)->post('/despachos', ['contenedor_id' => $this->container, 'transportista' => 'Retiro QA', 'conductor' => 'Carlos Rodas', 'placa_cabezal' => 'C123456', 'placa_chasis' => 'RE123456'])->assertSessionHasErrors('dispatch');
        $this->assertDatabaseHas('contenedores', ['id' => $this->container, 'estado_id' => 1]);
    }

    public function test_ingress_and_exit_pdf_documents_render_using_laravel_export_dependencies(): void
    {
        $this->actingAs($this->admin);
        $document = array_fill_keys(['tipo', 'modelo', 'iso_code', 'marca', 'naviera', 'marchamo', 'estado_unidad',
            'estado_box', 'ipsa', 'patio', 'bloque', 'posicion', 'transportista', 'conductor', 'placa_cabezal',
            'placa_chasis', 'referencia', 'auditora', 'auditoria', 'usuario'], 'Dato de prueba');
        $document += ['numero' => 'EIR-QA-INDEPENDIENTE', 'equipo' => 'SUDU5353440', 'fecha' => '29/10/2026', 'hora' => '10:00 hrs'];
        foreach (['ingress', 'dispatch'] as $type) {
            $event = DB::table('container_assignment_events')->insertGetId([
                'container_id' => $this->container, 'ingress_cycle_id' => $this->cycle, 'user_id' => $this->admin->id,
                'event_type' => $type, 'reason' => 'Constancia de prueba aislada', 'created_at' => now(),
                'after_data' => json_encode(['document' => $document]),
            ]);
            $route = $type === 'ingress' ? 'documents.ingress.ticket.pdf' : 'documents.dispatch.eir.pdf';
            $params = $type === 'ingress' ? ['cycle_id' => $this->cycle] : ['event_id' => $event];
            $response = $this->get(route($route, $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
        }
    }

    public function test_dispatch_creates_a_frozen_exit_eir_and_private_download_that_survives_reentry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 22:30:00', 'UTC'));
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1, 'marca' => 'Marca original']);
        $this->actingAs($this->admin);
        $this->get(route('documents.dispatch.eir', ['contenedor_id' => $this->container]))->assertNotFound();
        $response = $this->post(route('dispatches.store'), ['contenedor_id' => $this->container, 'transportista' => 'Transporte de salida',
            'conductor' => 'Conductor de salida', 'placa_cabezal' => 'c123456', 'placa_chasis' => 'TC123456',
            'observaciones' => 'Equipo entregado conforme a la revision, sin novedades.'])->assertSessionHasNoErrors();
        $event = DB::table('container_assignment_events')->where('container_id', $this->container)->where('event_type', 'dispatch')->first();
        $this->assertNotNull($event);
        $response->assertRedirect(route('documents.dispatch.ticket', ['event_id' => $event->id]));
        $this->assertDatabaseHas('contenedores', ['id' => $this->container, 'estado_id' => 2]);
        $this->assertNotNull(DB::table('container_ingress_cycles')->find($this->cycle)->closed_at);
        $this->assertDatabaseHas('bloques', ['id' => DB::table('contenedores')->find($this->container)->bloque_id, 'capacidad_actual' => 0]);
        $document = $this->get(route('documents.dispatch.ticket', ['event_id' => $event->id]))->assertOk()->assertViewIs('documents.ticket')
            ->assertSee('Ticket de salida')->assertSee('Salir')->assertSee('Imprimir')
            ->assertSee('class="ticket-sheet ticket-formal"', false)
            ->assertSee(route('documents.dispatch.ticket.pdf', ['event_id' => $event->id]), false)
            ->assertDontSee('Marchamo')->assertDontSee('El contenedor queda asignado al')
            ->assertDontSee('Ver PDF')->assertDontSee('Descargar EIR')->assertDontSee('id="eirHistoryHeading"', false);
        $this->get(route('documents.dispatch.eir', ['event_id' => $event->id]))->assertOk()->assertViewIs('documents.ticket');
        $this->get(route('documents.dispatch.eir'))->assertOk()->assertSee('id="eirHistoryHeading"', false);
        $snapshot = $document->viewData('data');
        $this->assertSame('25/10/2026', $snapshot['fecha']);
        $this->assertSame('16:30 hrs', $snapshot['hora']);
        $this->assertSame('C123456', $snapshot['placa_cabezal']);
        $this->assertSame('Transporte de salida', $snapshot['transportista']);
        $this->assertSame('Aprobado', $snapshot['auditoria']);
        $this->assertArrayNotHasKey('licencia', $snapshot);
        $this->assertArrayNotHasKey('marchamo', $snapshot);
        $this->assertSame($this->admin->username, $snapshot['usuario']);
        $pdf = $this->get(route('documents.dispatch.eir.pdf', ['event_id' => $event->id, 'download' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('attachment;', $pdf->headers->get('Content-Disposition'));
        $this->post(route('dispatches.store'), ['contenedor_id' => $this->container])->assertSessionHasErrors('contenedor_id');
        $this->assertSame(1, DB::table('container_assignment_events')->where('container_id', $this->container)->where('event_type', 'dispatch')->count());
        $cycle = (array) DB::table('container_ingress_cycles')->find($this->cycle);
        unset($cycle['id']);
        $newCycle = DB::table('container_ingress_cycles')->insertGetId(array_replace($cycle, ['closed_at' => null, 'entered_at' => now()->addDay()]));
        DB::table('contenedores')->where('id', $this->container)->update(['current_ingress_cycle_id' => $newCycle, 'marca' => 'Marca posterior', 'estado_id' => 1]);
        $this->get(route('documents.dispatch.eir', ['event_id' => $event->id]))->assertOk()->assertViewHas('data', $snapshot)->assertDontSee('Marca posterior');
        $this->get(route('documents.dispatch.ticket', ['event_id' => $event->id]))->assertOk()->assertViewHas('data', $snapshot)->assertDontSee('Marca posterior');
        $foreignBranch = DB::table('sucursales')->insertGetId(['nombre' => 'Sucursal ajena EIR', 'ubicacion' => 'Temporal', 'estado_id' => 1]);
        $this->admin->update(['rol_id' => 1]);
        $this->withSession(['sucursal_activa' => $foreignBranch])->get(route('documents.dispatch.eir', ['event_id' => $event->id]))->assertNotFound();
        $this->get(route('documents.dispatch.eir.pdf', ['event_id' => $event->id]))->assertNotFound();
        $this->get(route('documents.dispatch.ticket', ['event_id' => $event->id]))->assertNotFound();
    }

    public function test_driver_and_chassis_plate_are_required_in_ingress_edit_and_dispatch_with_contextual_help(): void
    {
        $this->actingAs($this->admin);
        foreach ([
            ['POST', route('ingresos.store'), route('ingresos.create')],
            ['PATCH', route('containers.update', $this->container), route('containers.edit', $this->container)],
            ['POST', route('dispatches.store'), route('dispatches.create')],
        ] as [$method, $endpoint, $page]) {
            $document = new \DOMDocument;
            @$document->loadHTML($this->get($page)->assertOk()->getContent(), LIBXML_NONET);
            $xpath = new \DOMXPath($document);
            foreach (['conductor', 'placa_chasis'] as $field) {
                $this->assertTrue($xpath->query('//input[@name="'.$field.'"]')->item(0)->hasAttribute('required'));
            }
            $this->assertGreaterThanOrEqual(4, $xpath->query('//*[@data-field-help]')->length);
            $this->json($method, $endpoint, ['conductor' => '', 'placa_chasis' => ''])
                ->assertUnprocessable()->assertJsonValidationErrors(['conductor', 'placa_chasis']);
            $this->json($method, $endpoint, ['conductor' => 'Juan Carlos Perez', 'placa_chasis' => 'RE123456'])
                ->assertUnprocessable()->assertJsonMissingValidationErrors(['conductor', 'placa_chasis']);
        }
    }

    public function test_operational_observations_need_40_characters_when_provided_but_can_remain_empty(): void
    {
        $this->actingAs($this->admin);
        foreach ([
            ['POST', route('ingresos.store'), ['observaciones_estado', 'observaciones_box']],
            ['PATCH', route('containers.update', $this->container), ['observaciones_estado', 'observaciones_box']],
            ['POST', route('dispatches.store'), ['observaciones']],
        ] as [$method, $endpoint, $fields]) {
            $text = substr('El equipo fue revisado antes de entregar al transportista.', 0, 40);
            $this->json($method, $endpoint, array_fill_keys($fields, substr($text, 0, 39)))
                ->assertUnprocessable()->assertJsonValidationErrors($fields);
            $this->json($method, $endpoint, array_fill_keys($fields, $text))
                ->assertUnprocessable()->assertJsonMissingValidationErrors($fields);
            $this->json($method, $endpoint, array_fill_keys($fields, '   '))
                ->assertUnprocessable()->assertJsonMissingValidationErrors($fields);
        }
    }

    public function test_equipment_and_transport_text_limits_match_forms_and_reject_only_values_above_the_limits(): void
    {
        $this->actingAs($this->admin);
        foreach ([
            ['POST', route('ingresos.store'), route('ingresos.create'), ['marca' => 40, 'conductor' => 80, 'transportista' => 100]],
            ['PATCH', route('containers.update', $this->container), route('containers.edit', $this->container), ['marca' => 40, 'conductor' => 80, 'transportista' => 100]],
            ['POST', route('dispatches.store'), route('dispatches.create'), ['conductor' => 80, 'transportista' => 100]],
        ] as [$method, $endpoint, $page, $limits]) {
            $document = new \DOMDocument;
            @$document->loadHTML($this->get($page)->assertOk()->getContent(), LIBXML_NONET);
            $xpath = new \DOMXPath($document);
            $valid = [];
            $invalid = [];
            foreach ($limits as $field => $max) {
                $this->assertSame((string) $max, $xpath->query('//input[@name="'.$field.'"]')->item(0)->getAttribute('maxlength'));
                $valid[$field] = substr('Ana Beatriz Cristina Daniela Elena Fernanda Gabriela Helena Isabel Josefina Karla Laura Mariana Natalia Olivia Patricia', 0, $max);
                $invalid[$field] = str_repeat('a', $max + 1);
            }
            $this->json($method, $endpoint, $invalid)->assertUnprocessable()->assertJsonValidationErrors(array_keys($limits));
            $this->json($method, $endpoint, $valid)->assertUnprocessable()->assertJsonMissingValidationErrors(array_keys($limits));
            if (isset($limits['marca'])) {
                $this->json($method, $endpoint, array_replace($valid, ['marca' => '3M']))
                    ->assertUnprocessable()->assertJsonMissingValidationErrors('marca');
            }
            foreach (['35426556565y', '3 5 4 2 6 5 5 6 5 6 5 y', '123456789012345', 'aaaaaaaaaaaa', 'qwertyuiop', 'texto de relleno texto de relleno texto de relleno'] as $filler) {
                $this->json($method, $endpoint, array_fill_keys(array_keys($limits), $filler))
                    ->assertUnprocessable()->assertJsonValidationErrors(array_keys($limits));
            }
        }
    }

    public function test_dispatch_location_selection_is_branch_scoped_and_container_edit_protects_navigation(): void
    {
        $this->actingAs($this->admin);
        $container = DB::table('contenedores')->find($this->container);
        $block = DB::table('bloques')->find($container->bloque_id);
        $this->get(route('dispatches.create'))->assertOk()
            ->assertSee('id="dispatchPatio"', false)->assertSee('id="dispatchBlock"', false)
            ->assertViewHas('dispatchLocations', fn ($locations) => $locations->contains('id', $block->patio_id));
        $this->getJson(route('dispatches.search', ['bloque_id' => $block->id]))->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.id', $this->container)
            ->assertJsonPath('0.patio_id', $block->patio_id)->assertJsonPath('0.puede_despachar', false);
        $emptyBlock = DB::table('bloques')->insertGetId(['numero' => 107, 'patio_id' => $block->patio_id, 'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1]);
        $this->getJson(route('dispatches.search', ['bloque_id' => $emptyBlock]))->assertOk()->assertExactJson([]);
        DB::table('bloques')->where('id', $emptyBlock)->update(['estado_id' => 2]);
        $this->getJson(route('dispatches.search', ['bloque_id' => $emptyBlock]))->assertNotFound();
        $foreignBranch = DB::table('sucursales')->insertGetId(['nombre' => 'Otra sucursal QA', 'ubicacion' => 'QA', 'estado_id' => 1]);
        $foreignYard = DB::table('patios')->insertGetId(['nombre' => 'Patio ajeno QA', 'sucursal_id' => $foreignBranch, 'estado_id' => 1]);
        $foreignBlock = DB::table('bloques')->insertGetId(['numero' => 1, 'patio_id' => $foreignYard, 'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1]);
        $this->getJson(route('dispatches.search', ['bloque_id' => $foreignBlock]))->assertNotFound();
        $this->getJson(route('dispatches.search', ['bloque_id' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('bloque_id');
        $this->get(route('containers.edit', $this->container))->assertOk()
            ->assertSee('data-unsaved-form="editCancelDialog"', false)->assertSee('data-unsaved-stay', false)
            ->assertSee('data-unsaved-leave', false);
        $this->get(route('containers.index', ['patio_id' => $block->patio_id, 'bloque_id' => $block->id]))
            ->assertOk()->assertDontSee('Constancia ingreso');
    }

    public function test_dispatch_transport_has_its_own_step_and_the_same_fields_and_requiredness_as_ingress(): void
    {
        $this->actingAs($this->admin);
        $html = $this->get(route('dispatches.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-unsaved-form="dispatchExitDialog"', $html);
        $this->assertStringContainsString('id="dispatchExitDialog"', $html);
        $this->get(route('transfers.create'))->assertOk()
            ->assertSee('data-unsaved-form="transferExitDialog"', false)
            ->assertSee('id="transferExitDialog"', false);
        $ingress = $this->get(route('ingresos.create'))->assertOk()->getContent();
        $parse = function (string $content): \DOMXPath {
            $document = new \DOMDocument;
            @$document->loadHTML($content, LIBXML_NONET);

            return new \DOMXPath($document);
        };
        $dispatchDom = $parse($html);
        $ingressDom = $parse($ingress);
        $this->assertSame(4, $dispatchDom->query('//*[@data-dispatch-step]')->length);
        $this->assertSame(0, $dispatchDom->query('//*[@data-dispatch-phase="1"]//input | //*[@data-dispatch-phase="1"]//textarea')->length);
        foreach (['transportista', 'conductor', 'placa_cabezal', 'placa_chasis'] as $field) {
            $input = $dispatchDom->query('//*[@data-dispatch-phase="2"]//input[@name="'.$field.'"]')->item(0);
            $source = $ingressDom->query('//input[@name="'.$field.'"]')->item(0);
            $this->assertNotNull($input, $field);
            $this->assertSame($source->hasAttribute('required'), $input->hasAttribute('required'), $field);
            $this->assertSame($source->getAttribute('maxlength'), $input->getAttribute('maxlength'), $field);
        }
        $this->assertSame(0, $dispatchDom->query('//*[@name="licencia" or @name="marchamo"]')->length);
        $this->assertSame(1, $dispatchDom->query('//*[@data-dispatch-phase="3"]//*[@id="dispatchConfirmTransport"]')->length);
        $this->postJson(route('dispatches.store'), ['contenedor_id' => $this->container])->assertUnprocessable()
            ->assertJsonValidationErrors(['transportista', 'placa_cabezal']);
        foreach (['12345678901234567890', str_repeat('texto de relleno ', 4), 'Se reviso aaaaaaaaaaaa'] as $notes) {
            $this->postJson(route('dispatches.store'), [
                'contenedor_id' => $this->container, 'transportista' => 'Retiro QA',
                'placa_cabezal' => 'C123456', 'observaciones' => $notes,
            ])->assertUnprocessable()->assertJsonValidationErrors('observaciones');
        }
        $this->assertDatabaseHas('contenedores', ['id' => $this->container, 'estado_id' => 1]);
        $this->assertSame(0, DB::table('container_assignment_events')->where('container_id', $this->container)->where('event_type', 'dispatch')->count());
    }

    public function test_dispatch_checks_availability_audit_cycle_and_physical_access_without_creating_documents_on_failure(): void
    {
        $this->actingAs($this->admin);
        $data = ['contenedor_id' => $this->container, 'transportista' => 'Retiro QA', 'conductor' => 'Juan Carlos Perez', 'placa_chasis' => 'RE123456', 'placa_cabezal' => 'C123456'];
        $this->post(route('dispatches.store'), $data)->assertSessionHasErrors('dispatch');
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        foreach (['Pendiente de revision', 'En revision', 'Rechazado'] as $status) {
            DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => $status]);
            $this->post(route('dispatches.store'), $data)->assertSessionHasErrors('dispatch');
        }
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Aprobado', 'ingress_cycle_id' => null]);
        $this->post(route('dispatches.store'), $data)->assertSessionHasErrors('dispatch');
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['ingress_cycle_id' => $this->cycle]);
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['closed_at' => now()]);
        $this->post(route('dispatches.store'), $data)->assertSessionHasErrors('dispatch');
        DB::table('container_ingress_cycles')->where('id', $this->cycle)->update(['closed_at' => null]);
        $container = (array) DB::table('contenedores')->find($this->container);
        unset($container['id']);
        DB::table('contenedores')->where('id', $this->container)->update(['posicion' => 2]);
        DB::table('contenedores')->insert(array_replace($container, ['nombre' => 'BLKU1234567', 'current_ingress_cycle_id' => null]));
        $this->post(route('dispatches.store'), $data)->assertSessionHasErrors('dispatch');
        $this->assertDatabaseHas('contenedores', ['id' => $this->container, 'estado_id' => 1]);
        $this->assertSame(0, DB::table('container_assignment_events')->where('container_id', $this->container)->where('event_type', 'dispatch')->count());
    }

    public function test_dispatch_search_agrees_with_open_maintenance_and_uses_available_status_name_not_id(): void
    {
        $job = $this->start();
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Aprobado']);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        $name = DB::table('contenedores')->find($this->container)->nombre;
        $this->actingAs($this->admin)->getJson(route('dispatches.search', ['query' => $name]))->assertOk()
            ->assertJsonPath('0.mantenimiento_pendiente', true)->assertJsonPath('0.puede_despachar', false);
        DB::table('maintenance_jobs')->where('id', $job)->update(['status' => 'completed']);
        DB::table('estatus_ipsa')->where('id', 1)->update(['nombre' => 'Pendiente QA']);
        $available = DB::table('estatus_ipsa')->insertGetId(['nombre' => 'Disponible', 'estado_id' => 1]);
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => $available]);
        $this->getJson(route('dispatches.search', ['query' => $name]))->assertOk()->assertJsonPath('0.puede_despachar', true);
        $this->post(route('dispatches.store'), ['contenedor_id' => $this->container, 'transportista' => 'Retiro QA', 'placa_cabezal' => str_repeat('1', 20)])
            ->assertSessionHasErrors('placa_cabezal');
        $this->post(route('dispatches.store'), ['contenedor_id' => $this->container, 'transportista' => 'Retiro QA', 'conductor' => 'Juan Carlos Perez', 'placa_chasis' => 'RE123456', 'placa_cabezal' => 'C123456'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contenedores', ['id' => $this->container, 'estado_id' => 2]);
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
            ->assertDontSee('XXXXXXXXXXX')->assertDontSee('Asignar auditoría')->assertSee('Auditoría externa')
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
            ->assertSee(route('corrections.show', $id), false)->assertSee('Atender corrección')
            ->set('maintenanceStatusFilter', 'Reparado')->assertDontSee($record->nombre);
        $this->get('/mantenimiento/ordenes/'.$id)->assertOk()->assertViewHas('job', fn (object $job): bool => $job->status === 'returned_for_repair');
        $this->get('/mantenimiento/ordenes/nueva')->assertRedirect(route('maintenance.orders'));
        $this->post('/mantenimiento/ordenes', ['container_id' => $this->container, 'diagnosis' => 'Intento de duplicar trabajo devuelto'])->assertSessionHasErrors('container_id');
        $this->actingAs($this->auditor)->patch('/contenedores/'.$this->container.'/pyp', [
            'estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'No debe aprobarse con trabajo devuelto',
        ])->assertSessionHasErrors('pyp');
        DB::table('contenedores')->where('id', $this->container)->update(['estatus_ipsa_id' => 1]);
        DB::table('pyp_auditoria')->where('contenedor_id', $this->container)->update(['estatus_pyp' => 'Aprobado']);
        $this->actingAs($this->admin)->post('/despachos', ['contenedor_id' => $this->container, 'transportista' => 'Retiro QA', 'conductor' => 'Carlos Rodas', 'placa_cabezal' => 'C123456', 'placa_chasis' => 'RE123456'])->assertSessionHasErrors('dispatch');
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
