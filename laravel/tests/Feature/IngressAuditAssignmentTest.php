<?php

namespace Tests\Feature;

use App\Livewire\Containers\InventoryTable;
use App\Models\Contenedor;
use App\Models\User;
use App\Services\PypAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class IngressAuditAssignmentTest extends TestCase
{
    private User $admin;

    private User $inspector;

    private int $branch;

    private int $yard;

    private int $block;

    private int $line;

    private int $company;

    private int $secondCompany;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedMysql();
        DB::beginTransaction();
        $this->branch = DB::table('sucursales')->insertGetId(['nombre' => 'QA Fase 5 '.uniqid(), 'ubicacion' => 'Prueba temporal', 'estado_id' => 1]);
        $this->yard = DB::table('patios')->insertGetId(['nombre' => 'Patio QA', 'sucursal_id' => $this->branch, 'estado_id' => 1]);
        $this->block = DB::table('bloques')->insertGetId(['numero' => 105, 'patio_id' => $this->yard, 'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1]);
        $this->line = DB::table('shipping_lines')->insertGetId(['name' => 'Naviera del Caribe QA', 'name_key' => 'naviera fase5 '.uniqid(), 'status' => 1]);
        DB::table('shipping_line_branch')->insert(['shipping_line_id' => $this->line, 'branch_id' => $this->branch]);
        $this->company = DB::table('external_audit_companies')->insertGetId(['name' => 'Inspecciones del Caribe QA', 'name_key' => 'auditora fase5 '.uniqid(), 'status' => 1]);
        $this->secondCompany = DB::table('external_audit_companies')->insertGetId(['name' => 'Servicios de Inspeccion QA', 'name_key' => 'auditora fase5b '.uniqid(), 'status' => 1]);
        foreach ([$this->company, $this->secondCompany] as $company) {
            DB::table('shipping_line_branch_auditor')->insert(['shipping_line_id' => $this->line, 'branch_id' => $this->branch, 'auditor_id' => $company]);
        }
        $this->admin = User::create(['username' => 'Admin_QA_'.uniqid(), 'email' => uniqid().'@example.test', 'password' => Hash::make('Secret123!'), 'rol_id' => 2, 'sucursal_id' => $this->branch, 'estado_id' => 1]);
        $this->inspector = User::create(['username' => 'Inspector_QA_'.uniqid(), 'email' => uniqid().'@example.test', 'password' => Hash::make('Secret123!'), 'rol_id' => 5, 'sucursal_id' => $this->branch, 'estado_id' => 1, 'external_audit_company_id' => $this->company, 'external_access_level' => 'inspector']);
        $this->inspector->authorizedExternalBranches()->sync([$this->branch]);
        $type = (int) DB::table('tipos_unidad')->where('estado_id', 1)->value('id');
        $this->payload = ['fecha_ingreso' => now()->format('Y-m-d'), 'codigo_contenedor' => 'PHAU'.random_int(1000000, 9999999),
            'tipo_unidad' => $type, 'modelo' => DB::table('modelos')->where('tipo_unidad_id', $type)->where('estado_id', 1)->value('id'),
            'iso_code' => '22G1', 'marca' => 'QA', 'observaciones_estado' => 'Se observa una falla en el sistema de refrigeracion de la unidad.', 'observaciones_box' => 'Se observa una deformacion en el panel lateral de la estructura.', 'estado_unidad' => DB::table('estatus_contenedores')->where('tipo_estatus_id', 1)->where('estado_id', 1)->value('id'),
            'status_box' => DB::table('estatus_contenedores')->where('tipo_estatus_id', 2)->where('estado_id', 1)->value('id'),
            'estatus_ipsa' => DB::table('estatus_ipsa')->where('nombre', 'Disponible')->where('estado_id', 1)->value('id'),
            'shipping_line_id' => $this->line, 'audit_company_id' => null, 'transportista' => 'Transportista QA', 'conductor' => 'Carlos Rodas', 'placa_cabezal' => 'C123ABC', 'placa_chasis' => 'TC123ABC',
            'bloque' => $this->block, 'posicion' => 25];
        $this->actingAs($this->admin)->withSession(['sucursal_activa' => $this->branch]);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function register(bool $confirmed = false): int
    {
        if ($confirmed) {
            $this->payload['audit_company_id'] = $this->company;
            $this->payload['audit_reference'] = 'Instruccion de naviera QA-105';
        }
        $this->post('/ingresos', $this->payload)->assertRedirect()->assertSessionHasNoErrors();

        return (int) DB::table('contenedores')->where('nombre', $this->payload['codigo_contenedor'])->value('id');
    }

    private function dispatchPayload(int $container): array
    {
        return ['contenedor_id' => $container, 'transportista' => 'Transportista QA',
            'conductor' => 'Carlos Rodas', 'placa_cabezal' => 'C123ABC', 'placa_chasis' => 'TC123ABC'];
    }

    public function test_damage_observations_are_required_for_each_affected_component_on_ingress_and_edit(): void
    {
        $id = $this->register();
        $unitType = DB::table('tipos_estatus')->where('nombre', 'Unidad')->value('id');
        $boxType = DB::table('tipos_estatus')->where('nombre', 'Estructura')->value('id');
        $statusId = fn (string $name, int $type): int => (int) DB::table('estatus_contenedores')
            ->where('nombre', $name)->where('tipo_estatus_id', $type)->where('estado_id', 1)->value('id');
        $damagedUnit = $statusId('DMG', $unitType);
        $damagedBox = $statusId('DMG', $boxType);
        $okUnit = $statusId('OK', $unitType);
        $okBox = $statusId('OK', $boxType);
        foreach ([
            [$damagedUnit, $okBox, ['observaciones_estado']],
            [$okUnit, $damagedBox, ['observaciones_box']],
            [$damagedUnit, $damagedBox, ['observaciones_estado', 'observaciones_box']],
            [$okUnit, $statusId('DMG Reparación Mínima', $boxType), ['observaciones_box']],
        ] as [$unit, $box, $errors]) {
            $payload = array_replace($this->payload, ['estado_unidad' => $unit, 'status_box' => $box,
                'observaciones_estado' => '   ', 'observaciones_box' => '   ']);
            foreach (['post' => '/ingresos', 'patch' => '/contenedores/'.$id] as $method => $url) {
                $response = $this->{$method.'Json'}($url, $payload)->assertUnprocessable()->assertJsonValidationErrors($errors);
                $this->assertCount(count($errors), $response->json('errors'));
            }
        }
        foreach ([[$okUnit, $okBox], [$statusId('Pendiente PTI', $unitType), $statusId('Pendiente Revisión', $boxType)]] as $index => [$unit, $box]) {
            $payload = array_replace($this->payload, ['codigo_contenedor' => 'OBSU'.random_int(1000000, 9999999),
                'estado_unidad' => $unit, 'status_box' => $box, 'observaciones_estado' => null, 'observaciones_box' => null, 'posicion' => 24 - $index]);
            $this->post('/ingresos', $payload)->assertSessionHasNoErrors()->assertRedirect();
            $this->patch('/contenedores/'.$id, array_replace($payload, ['codigo_contenedor' => $this->payload['codigo_contenedor']]))
                ->assertSessionHasNoErrors()->assertRedirect();
        }
    }

    public function test_audit_has_four_real_states_with_separate_assignment_notices(): void
    {
        $id = $this->register(true);
        $table = Livewire::test(InventoryTable::class)->set('patioId', (string) $this->yard)->set('blockId', (string) $this->block);
        $table->assertViewHas('containers', fn (array $rows): bool => $rows[0]->audit_status === 'Pendiente de revision' && $rows[0]->audit_assignment_notice === null);
        $this->actingAs($this->inspector);
        $this->assertSame(['Pendiente de revision', 'En revision', 'Aprobado', 'Rechazado'], PypAuditService::STATES);
        $this->get('/contenedores/'.$id.'/pyp')->assertRedirect(route('pyp-audits.history', ['container' => $id]));
        $revision = fn (): int => (int) DB::table('container_assignment_events')->where('container_id', $id)->max('id');
        $this->post(route('pyp-audits.begin', $id), ['audit_version' => $revision()])->assertSessionHasNoErrors();
        $this->get('/contenedores/'.$id.'/pyp')->assertOk()->assertSee('data-admin-select', false)->assertSee('Aprobado')->assertSee('Rechazado');
        Livewire::test(InventoryTable::class, ['pypMode' => true])->set('search', $this->payload['codigo_contenedor'])
            ->set('pypStatusFilter', 'En revision')->assertViewHas('containers', fn (array $rows): bool => count($rows) === 1);
        foreach (['En revision', 'Pendiente de revision', 'Texto inventado'] as $invalid) {
            $this->patch('/contenedores/'.$id.'/pyp', ['audit_version' => $revision(), 'estatus_pyp' => $invalid,
                'informacion_adicional' => 'La inspeccion tecnica confirma que el contenedor esta en buen estado.'])->assertSessionHasErrors('estatus_pyp');
        }
        $this->patch('/contenedores/'.$id.'/pyp', ['audit_version' => $revision(), 'estatus_pyp' => 'Rechazado', 'informacion_adicional' => '   '])
            ->assertSessionHasErrors('informacion_adicional');
        $this->patch('/contenedores/'.$id.'/pyp', ['audit_version' => $revision(), 'estatus_pyp' => 'Aprobado',
            'informacion_adicional' => 'La inspeccion tecnica confirma que el contenedor esta en buen estado.'])->assertSessionHasNoErrors();
        Livewire::test(InventoryTable::class, ['pypMode' => true])->set('search', $this->payload['codigo_contenedor'])
            ->set('pypStatusFilter', 'Aprobado')->assertViewHas('containers', fn (array $rows): bool => count($rows) === 1 && $rows[0]->audit_status === 'Aprobado');
        $events = DB::table('container_assignment_events')->where('container_id', $id)->where('event_type', 'external_audit_status')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame('En revision', json_decode($events[1]->before_data, true)['audit']['estatus_pyp']);
        $this->assertSame('Aprobado', json_decode($events[1]->after_data, true)['audit']['estatus_pyp']);
        $this->assertSame($this->inspector->id, (int) $events[1]->user_id);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $id, 'estatus_pyp' => 'Aprobado']);
    }

    public function test_unassigned_ingress_has_no_audit_status_or_status_filter_match(): void
    {
        $id = $this->register();
        $table = Livewire::test(InventoryTable::class)->set('patioId', (string) $this->yard)->set('blockId', (string) $this->block);
        $table->assertViewHas('containers', fn (array $rows): bool => $rows[0]->audit_status === null && $rows[0]->audit_assignment_notice === 'Sin auditora asignada')
            ->assertSee('Sin auditora asignada')->assertDontSee('aria-label="Sin resultado de auditoria"', false)
            ->assertDontSee('Pendiente de regularizacion')
            ->set('pypStatusFilter', 'Pendiente de revision')->assertViewHas('containers', []);
        DB::table('contenedores')->where('id', $id)->update(['current_ingress_cycle_id' => null]);
        $table->call('clearFilters')->assertViewHas('containers', fn (array $rows): bool => $rows[0]->audit_status === null && $rows[0]->audit_assignment_notice === 'Pendiente de regularizacion')
            ->assertSee('Sin auditora asignada')->assertDontSee('Pendiente de regularizacion')->assertDontSee('aria-label="Sin resultado de auditoria"', false);
    }

    public function test_pending_ingress_is_not_visible_to_any_external_company_and_cannot_be_dispatched(): void
    {
        $id = $this->register();
        $this->assertDatabaseHas('container_ingress_cycles', ['container_id' => $id, 'shipping_line_id' => $this->line, 'audit_company_id' => null]);
        $this->get('/contenedores/'.$id)->assertOk()->assertSee('Pendiente de confirmacion');
        $this->post('/despachos', $this->dispatchPayload($id))->assertSessionHasErrors('dispatch');
        $this->actingAs($this->inspector);
        Livewire::test(InventoryTable::class, ['pypMode' => true])->set('search', $this->payload['codigo_contenedor'])->assertDontSee($this->payload['codigo_contenedor']);
        $this->get('/contenedores/'.$id.'/pyp')->assertRedirect()->assertSessionHasErrors('pyp');
    }

    public function test_ingress_rejects_free_text_wrong_branches_and_unauthorized_or_inactive_auditors(): void
    {
        $this->postJson('/ingresos', array_replace($this->payload, ['shipping_line_id' => null, 'naviera' => 'Texto inventado']))
            ->assertUnprocessable()->assertJsonValidationErrors('shipping_line_id');
        DB::table('shipping_lines')->where('id', $this->line)->update(['status' => 2]);
        $this->postJson('/ingresos', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('shipping_line_id');
        DB::table('shipping_lines')->where('id', $this->line)->update(['status' => 1]);
        $otherBranch = DB::table('sucursales')->insertGetId(['nombre' => 'Otra sucursal QA', 'estado_id' => 1]);
        $foreignLine = DB::table('shipping_lines')->insertGetId(['name' => 'Naviera de otra sucursal QA', 'name_key' => 'naviera ajena '.uniqid(), 'status' => 1]);
        DB::table('shipping_line_branch')->insert(['shipping_line_id' => $foreignLine, 'branch_id' => $otherBranch]);
        $this->postJson('/ingresos', array_replace($this->payload, ['shipping_line_id' => $foreignLine]))
            ->assertUnprocessable()->assertJsonValidationErrors('shipping_line_id');
        DB::table('shipping_line_branch_auditor')->where('shipping_line_id', $this->line)->where('auditor_id', $this->secondCompany)->delete();
        $this->postJson('/ingresos', array_replace($this->payload, ['audit_company_id' => $this->secondCompany]))
            ->assertUnprocessable()->assertJsonValidationErrors('audit_company_id');
        DB::table('external_audit_companies')->where('id', $this->company)->update(['status' => 2]);
        $this->postJson('/ingresos', array_replace($this->payload, ['audit_company_id' => $this->company]))
            ->assertUnprocessable()->assertJsonValidationErrors('audit_company_id');
        $this->assertDatabaseMissing('contenedores', ['nombre' => $this->payload['codigo_contenedor']]);
    }

    public function test_assignment_changes_need_permission_and_reason(): void
    {
        $id = $this->register();
        $change = array_replace($this->payload, ['audit_company_id' => $this->company, 'audit_reference' => 'Instruccion QA']);
        $this->patchJson('/contenedores/'.$id, $change)->assertUnprocessable()->assertJsonValidationErrors('assignment_reason');
        $limited = clone $this->admin;
        $limited->permissions = ['containers.view', 'containers.edit', 'containers.create'];
        $this->actingAs($limited)->patchJson('/contenedores/'.$id, $change + ['assignment_reason' => 'Confirmacion posterior'])->assertForbidden();
        $this->actingAs($this->admin)->patch('/contenedores/'.$id, $change + ['assignment_reason' => 'Confirmacion posterior'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $id, 'external_audit_company_id' => $this->company, 'estado_id' => 1]);
        $this->actingAs($this->inspector);
        Livewire::test(InventoryTable::class, ['pypMode' => true])->set('search', $this->payload['codigo_contenedor'])->assertSee($this->payload['codigo_contenedor']);
    }

    public function test_assignment_observations_are_optional_and_can_be_cleared_without_invalidating_approval(): void
    {
        $this->payload['audit_company_id'] = $this->company;
        $id = $this->register();
        $cycle = Contenedor::findOrFail($id)->current_ingress_cycle_id;
        $this->assertDatabaseHas('container_ingress_cycles', ['id' => $cycle, 'audit_reference' => null]);
        $this->get('/contenedores/'.$id.'/editar')->assertOk()
            ->assertSee('Observaciones de la asignacion')->assertSee('(opcional)')
            ->assertSee('data-help-touch', false)->assertSee('assignmentNotesHelp');
        DB::table('pyp_auditoria')->where('contenedor_id', $id)->update(['estatus_pyp' => 'Aprobado']);
        $this->patch('/contenedores/'.$id, $this->payload + ['audit_reference' => 'Confirmacion por llamada'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('container_ingress_cycles', ['id' => $cycle, 'audit_reference' => 'Confirmacion por llamada']);
        $this->patch('/contenedores/'.$id, $this->payload + ['audit_reference' => ''])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('container_ingress_cycles', ['id' => $cycle, 'audit_reference' => null]);
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $id, 'estatus_pyp' => 'Aprobado']);
    }

    public function test_reassignment_archives_the_previous_approval_and_requires_a_new_verdict(): void
    {
        $id = $this->register(true);
        $cycle = Contenedor::findOrFail($id)->current_ingress_cycle_id;
        DB::table('pyp_auditoria')->where('contenedor_id', $id)->update(['estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'Aprobacion original QA']);
        $this->patch('/contenedores/'.$id, array_replace($this->payload, ['audit_company_id' => $this->secondCompany,
            'audit_reference' => 'Nueva instruccion QA', 'assignment_reason' => 'Naviera solicita otra auditora']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $id, 'estatus_pyp' => 'Asignado', 'external_audit_company_id' => $this->secondCompany, 'ingress_cycle_id' => $cycle]);
        $event = DB::table('container_assignment_events')->where('container_id', $id)->where('event_type', 'reassignment')->first();
        $this->assertSame('Aprobado', json_decode($event->before_data, true)['audit']['estatus_pyp']);
        $this->assertSame($this->admin->id, $event->user_id);
        $this->post('/despachos', $this->dispatchPayload($id))->assertSessionHasErrors('dispatch');
        $this->actingAs($this->inspector);
        Livewire::test(InventoryTable::class, ['pypMode' => true])->set('search', $this->payload['codigo_contenedor'])->assertDontSee($this->payload['codigo_contenedor']);
        $secondInspector = clone $this->inspector;
        $secondInspector->external_audit_company_id = $this->secondCompany;
        $this->actingAs($secondInspector);
        Livewire::test(InventoryTable::class, ['pypMode' => true])->set('search', $this->payload['codigo_contenedor'])->assertSee($this->payload['codigo_contenedor']);
    }

    public function test_ingress_permission_does_not_implicitly_grant_auditor_assignment_permission(): void
    {
        $limited = clone $this->admin;
        $limited->permissions = ['containers.view', 'containers.create'];
        $this->actingAs($limited);
        $this->postJson('/ingresos', array_replace($this->payload, ['audit_company_id' => $this->company, 'audit_reference' => 'Instruccion QA']))->assertForbidden();
        $id = $this->register();
        $this->assertDatabaseHas('container_ingress_cycles', ['container_id' => $id, 'audit_company_id' => null]);
    }

    public function test_shipping_line_change_also_invalidates_approval_even_with_the_same_auditor(): void
    {
        $id = $this->register(true);
        DB::table('pyp_auditoria')->where('contenedor_id', $id)->update(['estatus_pyp' => 'Aprobado']);
        $line = DB::table('shipping_lines')->insertGetId(['name' => 'Otra naviera QA', 'name_key' => 'otra naviera '.uniqid(), 'status' => 1]);
        DB::table('shipping_line_branch')->insert(['shipping_line_id' => $line, 'branch_id' => $this->branch]);
        DB::table('shipping_line_branch_auditor')->insert(['shipping_line_id' => $line, 'branch_id' => $this->branch, 'auditor_id' => $this->company]);
        $this->patch('/contenedores/'.$id, array_replace($this->payload, ['shipping_line_id' => $line, 'assignment_reason' => 'Cambio de naviera confirmado']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $id, 'estatus_pyp' => 'Asignado']);
        $this->assertDatabaseHas('contenedores', ['id' => $id, 'naviera' => 'Otra naviera QA']);
    }

    public function test_reentry_opens_a_new_cycle_without_inheriting_the_previous_approval(): void
    {
        $id = $this->register(true);
        $oldCycle = Contenedor::findOrFail($id)->current_ingress_cycle_id;
        DB::table('pyp_auditoria')->where('contenedor_id', $id)->update(['estatus_pyp' => 'Aprobado']);
        $this->post('/despachos', $this->dispatchPayload($id))->assertSessionHasNoErrors();
        $this->post('/ingresos', $this->payload)->assertSessionHasNoErrors();
        $this->assertNotSame($oldCycle, Contenedor::findOrFail($id)->current_ingress_cycle_id);
        $this->assertNotNull(DB::table('container_ingress_cycles')->where('id', $oldCycle)->value('closed_at'));
        $this->assertSame(2, DB::table('container_ingress_cycles')->where('container_id', $id)->count());
        $event = DB::table('container_assignment_events')->where('container_id', $id)->where('event_type', 'reentry')->first();
        $this->assertSame('Aprobado', json_decode($event->before_data, true)['audit']['estatus_pyp']);
        $this->post('/despachos', $this->dispatchPayload($id))->assertSessionHasErrors('dispatch');
    }

    public function test_catalog_deactivation_preserves_history_but_prevents_continuing_external_inspection(): void
    {
        $id = $this->register(true);
        DB::table('pyp_auditoria')->where('contenedor_id', $id)->update(['estatus_pyp' => 'Aprobado']);
        DB::table('shipping_lines')->where('id', $this->line)->update(['status' => 2]);
        $this->patch('/contenedores/'.$id, $this->payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pyp_auditoria', ['contenedor_id' => $id, 'estatus_pyp' => 'Aprobado']);
        $this->assertSame(1, DB::table('container_assignment_events')->where('container_id', $id)->count());
        $this->actingAs($this->inspector)->patch('/contenedores/'.$id.'/pyp', ['estatus_pyp' => 'Aprobado', 'informacion_adicional' => 'Intento posterior'])->assertSessionHasErrors('pyp');
    }

    public function test_forms_information_and_inventory_expose_assignment_without_automatically_selecting_an_auditor(): void
    {
        $this->get('/ingresos')->assertOk()->assertSee('data-container-assignment', false);
        $id = $this->register();
        $this->get('/contenedores/'.$id.'/editar')->assertOk()->assertSee('Pendiente de confirmacion')
            ->assertDontSee('container-edit-transfer')->assertDontSee('>Trasladar</a>', false);
        $this->patch('/contenedores/'.$id, array_replace($this->payload, ['patio_id' => $this->yard, 'bloque_id' => $this->block]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('containers.show', ['container' => $id, 'patio_id' => $this->yard, 'bloque_id' => $this->block]))
            ->assertSessionHas('status');
        $this->get('/contenedores/'.$id)->assertOk()->assertSee('Asignacion de auditoria externa')->assertSee('Naviera del Caribe QA');
        $this->get('/contenedores?patio_id='.$this->yard.'&bloque_id='.$this->block)->assertOk()->assertSee('Auditoría externa');
        $cycle = DB::table('container_ingress_cycles')->where('container_id', $id)->first();
        $this->assertNull($cycle->audit_company_id);
    }

    public function test_legacy_records_are_not_automatically_matched_or_approved_for_dispatch(): void
    {
        $id = $this->register();
        DB::table('contenedores')->where('id', $id)->update(['current_ingress_cycle_id' => null, 'naviera' => 'Naviera historica sin vincular']);
        DB::table('pyp_auditoria')->where('contenedor_id', $id)->update(['estatus_pyp' => 'Aprobado', 'estado_id' => 1, 'ingress_cycle_id' => null]);
        $payload = $this->payload;
        unset($payload['shipping_line_id']);
        $payload['naviera'] = 'Nombre manipulado';
        $this->patch('/contenedores/'.$id, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contenedores', ['id' => $id, 'naviera' => 'Naviera historica sin vincular', 'current_ingress_cycle_id' => null]);
        $this->post('/despachos', $this->dispatchPayload($id))->assertSessionHasErrors('dispatch');
        $this->get('/contenedores/'.$id)->assertOk()->assertSee('Pendiente de regularizacion');
        $this->getJson(route('dispatches.search', ['query' => $this->payload['codigo_contenedor']]))->assertOk()
            ->assertJsonPath('0.auditoria_aprobada', false)->assertJsonPath('0.puede_despachar', false);
    }

    public function test_legacy_empty_shipping_line_and_empty_catalog_explain_the_state(): void
    {
        $id = $this->register();
        DB::table('contenedores')->where('id', $id)->update(['current_ingress_cycle_id' => null, 'naviera' => null]);
        DB::table('shipping_lines')->where('id', $this->line)->update(['status' => 2]);
        $this->get('/contenedores/'.$id.'/editar')->assertOk()
            ->assertSee('Sin naviera registrada')
            ->assertSee('No hay navieras activas autorizadas para esta sucursal.')
            ->assertDontSee('Naviera anterior (registro anterior)');
    }

    public function test_inventory_distinguishes_missing_yards_and_blocks_from_an_unselected_layout(): void
    {
        Livewire::test(InventoryTable::class)->assertSee('Selecciona un patio para comenzar')
            ->assertDontSee('Esta sucursal no tiene patios activos');
        DB::table('bloques')->where('id', $this->block)->update(['estado_id' => 2]);
        Livewire::test(InventoryTable::class)->assertSee('Esta sucursal no tiene bloques activos')
            ->assertSee('Configurar patios y bloques')
            ->assertSee('class="container-new-button" disabled', false)
            ->assertDontSee('Selecciona un patio para comenzar');
        Livewire::test(InventoryTable::class)->set('patioId', (string) $this->yard)
            ->assertSee('Este patio no tiene bloques activos')->assertSee('Sin bloques')
            ->assertDontSee('Selecciona un bloque para ver la tabla')
            ->call('setViewMode', 'yard3d')->assertSee('Este patio no tiene bloques activos')
            ->assertDontSee('data-yard-scene=', false);
        DB::table('patios')->where('id', $this->yard)->update(['estado_id' => 2]);
        Livewire::test(InventoryTable::class)->assertSee('Esta sucursal no tiene patios activos')->assertSee('Sin patios')
            ->assertSee('Configurar patios y bloques')->assertDontSee('Selecciona un patio para comenzar');
    }

    public function test_inventory_configuration_action_respects_permissions_and_other_usable_yards(): void
    {
        $emptyYard = DB::table('patios')->insertGetId(['nombre' => 'Patio sin bloques QA', 'sucursal_id' => $this->branch, 'estado_id' => 1]);
        $this->register();
        Livewire::test(InventoryTable::class, ['pypMode' => true])->set('patioId', (string) $emptyYard)
            ->set('search', $this->payload['codigo_contenedor'])->assertSee($this->payload['codigo_contenedor'])
            ->assertDontSee('Este patio no tiene bloques activos');
        $limited = clone $this->admin;
        $limited->permissions = ['containers.view', 'containers.create'];
        $this->actingAs($limited);
        Livewire::test(InventoryTable::class)->set('patioId', (string) $emptyYard)
            ->assertSee('Este patio no tiene bloques activos')
            ->assertSee('Hay bloques activos en otros patios de esta sucursal.')
            ->assertDontSee('Configurar patios y bloques')
            ->assertDontSee('class="container-new-button" disabled', false);
        DB::table('patios')->where('sucursal_id', $this->branch)->update(['estado_id' => 2]);
        Livewire::test(InventoryTable::class)->assertSee('Solicita la configuracion al administrador de la sucursal.')
            ->assertDontSee('Configurar patios y bloques');
        $this->actingAs($this->inspector);
        Livewire::test(InventoryTable::class, ['pypMode' => true])->assertSee('Esta sucursal no tiene patios activos')
            ->assertDontSee('Configurar patios y bloques');
    }
}
