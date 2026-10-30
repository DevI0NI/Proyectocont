<?php

namespace Tests\Feature;

use App\Livewire\Containers\InventoryTable;
use App\Models\User;
use App\Services\ContainerUpdateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OperationalFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedMysql();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_ingreso_traslado_pyp_despacho_and_report_flow(): void
    {
        $branchId = DB::table('sucursales')->insertGetId([
            'nombre' => 'QA Flow '.uniqid(),
            'ubicacion' => 'Temporal',
            'estado_id' => 1,
        ]);
        $patioId = DB::table('patios')->insertGetId([
            'nombre' => 'QA Patio '.uniqid(),
            'sucursal_id' => $branchId,
            'estado_id' => 1,
        ]);
        $originBlockId = DB::table('bloques')->insertGetId([
            'numero' => 101,
            'patio_id' => $patioId,
            'capacidad_maxima' => 25,
            'capacidad_actual' => 0,
            'estado_id' => 1,
        ]);
        $destinationBlockId = DB::table('bloques')->insertGetId([
            'numero' => 102,
            'patio_id' => $patioId,
            'capacidad_maxima' => 25,
            'capacidad_actual' => 0,
            'estado_id' => 1,
        ]);

        $admin = $this->createUser($branchId, 2, 'admin');
        $pyp = $this->createUser($branchId, 5, 'pyp');
        $auditCompanyId = $this->configureExternalUser($pyp, $branchId);
        $shippingLineId = DB::table('shipping_lines')->insertGetId(['name' => 'Naviera QA '.uniqid(), 'name_key' => 'naviera qa '.uniqid(), 'status' => 1]);
        DB::table('shipping_line_branch')->insert(['shipping_line_id' => $shippingLineId, 'branch_id' => $branchId]);
        DB::table('shipping_line_branch_auditor')->insert(['shipping_line_id' => $shippingLineId, 'branch_id' => $branchId, 'auditor_id' => $auditCompanyId]);
        $catalog = $this->catalog();
        $containerCode = 'TSTU'.random_int(1000000, 9999999);

        $ingressData = [
            'fecha_ingreso' => now()->format('Y-m-d'),
            'codigo_contenedor' => $containerCode,
            'tipo_unidad' => $catalog['type_id'],
            'modelo' => $catalog['model_id'],
            'iso_code' => '22G1',
            'marca' => 'QA',
            'estado_unidad' => $catalog['unit_status_id'],
            'observaciones_estado' => 'La unidad fue inspeccionada y no presenta fallas de funcionamiento.',
            'status_box' => $catalog['box_status_id'],
            'observaciones_box' => 'La estructura fue inspeccionada y no presenta deformaciones visibles.',
            'estatus_ipsa' => $catalog['available_ipsa_id'],
            'shipping_line_id' => $shippingLineId,
            'audit_company_id' => $auditCompanyId,
            'audit_reference' => 'Instruccion QA',
            'transportista' => 'Transportista QA',
            'conductor' => 'Carlos Rodas',
            'placa_cabezal' => '123456',
            'placa_chasis' => 'TC-12ABC',
            'bloque' => $originBlockId,
            'posicion' => 25,
        ];
        $this->actingAs($admin)->withSession(['sucursal_activa' => $branchId]);
        $this->get('/ingresos')->assertOk()->assertViewHas('isoCodes', fn ($codes) => count($codes) === 33)
            ->assertSee('data-iso-search', false)->assertSee('value="45R1"', false)->assertSee('value="22T6"', false);
        foreach (['ZZZZ', '54353'] as $invalidIso) {
            $this->postJson('/ingresos', array_replace($ingressData, ['iso_code' => $invalidIso]))->assertUnprocessable()->assertJsonValidationErrors('iso_code');
        }
        $this->postJson('/ingresos', array_replace($ingressData, [
            'observaciones_estado' => str_repeat('a', 256),
            'observaciones_box' => str_repeat('b', 256),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['observaciones_estado', 'observaciones_box']);
        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->post('/ingresos', $ingressData)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $containerId = (int) DB::table('contenedores')->where('nombre', $containerCode)->value('id');
        $this->assertGreaterThan(0, $containerId);
        $this->patchJson('/contenedores/'.$containerId, array_replace($ingressData, ['iso_code' => 'ZZZZ']))->assertUnprocessable()->assertJsonValidationErrors('iso_code');
        DB::table('contenedores')->where('id', $containerId)->update(['iso_code' => '34H3']);
        $this->get('/contenedores/'.$containerId.'/editar')->assertOk()->assertSee('value="34H3" selected', false);
        $this->patch('/contenedores/'.$containerId, array_replace($ingressData, ['iso_code' => '34H3']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contenedores', ['id' => $containerId, 'observaciones_unidad' => $ingressData['observaciones_estado'], 'observaciones_box' => $ingressData['observaciones_box']]);
        $this->patchJson('/contenedores/'.$containerId, array_replace($ingressData, [
            'observaciones_estado' => str_repeat('a', 256),
            'observaciones_box' => str_repeat('b', 256),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['observaciones_estado', 'observaciones_box']);
        foreach (['/traslados/buscar', '/despachos/contenedores'] as $path) {
            $this->getJson($path.'?query='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors('query');
        }
        $this->getJson('/contenedores?search='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->getJson('/contenedores?nombre='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->assertNull(DB::table('contenedores')->where('id', $containerId)->value('placa_anio'));
        $this->assertDatabaseHas('contenedores', ['id' => $containerId, 'placa_cabezal' => '123456', 'placa_chasis' => 'TC-12ABC']);
        foreach (['/documentos/ingreso/eir', '/documentos/ingreso/ticket'] as $path) {
            $this->get($path.'?contenedor_id='.$containerId)->assertOk()
                ->assertSee('TC-12ABC')->assertDontSee('Anio Placa')->assertDontSee('cabezal / anio');
        }

        foreach (['/ingresos', "/contenedores/{$containerId}/editar", "/contenedores/{$containerId}"] as $path) {
            $this->get($path)->assertOk()->assertDontSee('name="placa_anio"', false)->assertDontSee('Anio placa');
        }
        foreach ([['c-123abc', 'tc 12abc'], ['ABC-1234', '123456'], ['123ABC', 'TC123ABC'], ['C123456', 'RE123456'], ['123456', '123456']] as [$headPlate, $chassisPlate]) {
            $this->patch("/contenedores/{$containerId}", array_replace($ingressData, [
                'placa_cabezal' => $headPlate,
                'placa_chasis' => $chassisPlate,
                'placa_anio' => 1900,
            ]))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('contenedores', [
                'id' => $containerId,
                'placa_cabezal' => strtoupper($headPlate),
                'placa_chasis' => strtoupper($chassisPlate),
                'placa_anio' => null,
            ]);
        }
        $this->patch("/contenedores/{$containerId}", array_replace($ingressData, [
            'placa_cabezal' => '',
            'placa_chasis' => str_repeat('A', 16),
        ]))->assertSessionHasErrors(['placa_cabezal', 'placa_chasis']);
        foreach (['12345', '123456789', 'C/123ABC', 'ABCDEF', 'C--123ABC', 'C123ABC!'] as $invalidPlate) {
            foreach (['post', 'patch'] as $method) {
                $url = $method === 'post' ? '/ingresos' : "/contenedores/{$containerId}";
                $this->{$method}($url, array_replace($ingressData, [
                    'placa_cabezal' => $invalidPlate,
                    'placa_chasis' => $invalidPlate,
                ]))->assertSessionHasErrors(['placa_cabezal', 'placa_chasis']);
            }
        }

        $inactiveContainer = (array) DB::table('contenedores')->where('id', $containerId)->first();
        $limited = clone $admin;
        $limited->permissions = ['containers.view', 'containers.edit'];
        $this->actingAs($limited);
        $this->get('/contenedores/'.$containerId.'/editar')->assertOk()
            ->assertDontSee('<select name="estado_unidad"', false)->assertDontSee('<select name="status_box"', false)
            ->assertDontSee('<select name="estatus_ipsa"', false);
        $this->get('/ingresos')->assertForbidden();
        $this->get('/ingresos/modelos/'.$catalog['type_id'])->assertOk();
        $this->post('/traslados', ['contenedor_id' => $containerId])->assertForbidden();
        $this->post('/despachos', ['contenedor_id' => $containerId])->assertForbidden();
        foreach (['estado_unidad' => 'estatus_unidad_id', 'status_box' => 'estatus_box_id', 'estatus_ipsa' => 'estatus_ipsa_id'] as $field => $column) {
            try {
                app(ContainerUpdateService::class)->update($limited, $branchId, $containerId, array_replace($ingressData, [$field => $inactiveContainer[$column] + 100000]));
                $this->fail('A restricted state change was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        $this->assertSame((int) $inactiveContainer['estatus_ipsa_id'], (int) DB::table('contenedores')->where('id', $containerId)->value('estatus_ipsa_id'));
        $this->actingAs($admin);
        unset($inactiveContainer['id']);
        $inactiveContainer['nombre'] = 'INAC'.random_int(1000000, 9999999);
        $inactiveContainer['posicion'] = 24;
        $inactiveContainer['estado_id'] = 2;
        DB::table('contenedores')->insert($inactiveContainer);
        DB::table('bloques')->where('id', $originBlockId)->update(['capacidad_actual' => 3]);

        foreach (['/traslados/bloques/', '/ingresos/bloques/'] as $path) {
            $this->actingAs($admin)
                ->withSession(['sucursal_activa' => $branchId])
                ->get($path.$patioId)
                ->assertOk()
                ->assertJsonFragment([
                    'id' => $originBlockId,
                    'capacidad_actual' => 1,
                ]);
        }

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/ingresos/bloque-preview/'.$originBlockId)
            ->assertOk()
            ->assertJsonPath('block.capacidad_actual', 1)
            ->assertJsonCount(1, 'containers')
            ->assertJsonPath('containers.0.posicion', 25);

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/traslados?contenedor_id='.$containerId)
            ->assertOk()
            ->assertSee('data-transfer-phase="0"', false)
            ->assertSee('data-transfer-phase="1"', false)
            ->assertSee('data-transfer-phase="2"', false)
            ->assertSee('class="transfer-workspace"', false)
            ->assertSee('id="globalSearchResults"', false)
            ->assertSeeInOrder([
                'id="transferPreviewCanvas"',
                'id="originMessage"',
                'id="destinationMessage"',
                'id="transferActions"',
            ], false)
            ->assertSeeInOrder([
                'id="confirmContainer"',
                'id="confirmOrigin"',
                'id="confirmDestination"',
            ], false)
            ->assertDontSee('id="confirmPosition"', false)
            ->assertDontSee('class="transfer-footer"', false);

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/ingresos/bloque-preview/'.$originBlockId)
            ->assertOk()
            ->assertJsonPath('containers.0.id', $containerId);

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/traslados/buscar?query='.$containerCode)
            ->assertOk()
            ->assertJsonFragment([
                'id' => $containerId,
                'patio_id' => $patioId,
                'bloque_id' => $originBlockId,
                'puede_trasladar' => true,
            ]);

        $otherBranchId = DB::table('sucursales')->insertGetId([
            'nombre' => 'QA Other '.uniqid(),
            'ubicacion' => 'Temporal',
            'estado_id' => 1,
        ]);
        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $otherBranchId])
            ->get('/traslados/buscar?query='.$containerCode)
            ->assertOk()
            ->assertExactJson([]);

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->post('/traslados', [
                'contenedor_id' => $containerId,
                'nuevo_bloque_id' => $originBlockId,
                'nueva_posicion' => 24,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('transfer');
        $this->assertSame($originBlockId, (int) DB::table('contenedores')->where('id', $containerId)->value('bloque_id'));

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->post('/traslados', [
                'contenedor_id' => $containerId,
                'nuevo_bloque_id' => $destinationBlockId,
                'nueva_posicion' => 25,
            ])
            ->assertRedirect('/traslados')
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get("/seguimiento-pyp?patio_id={$patioId}&bloque_id={$destinationBlockId}")
            ->assertRedirect(route('containers.index', ['patio_id' => $patioId, 'bloque_id' => $destinationBlockId]));

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get("/contenedores?patio_id={$patioId}&bloque_id={$destinationBlockId}&search={$containerCode}")
            ->assertOk()
            ->assertSee('Inventario del Bloque')
            ->assertSee($containerCode)
            ->assertSee('Bloque 102');

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->post("/contenedores/{$containerId}/pyp/asignar")
            ->assertRedirect('/seguimiento-pyp')
            ->assertSessionHasErrors('pyp');

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/despachos?contenedor_id='.$containerId)
            ->assertOk()
            ->assertSee('data-dispatch-phase="0"', false)
            ->assertSee('data-dispatch-phase="1"', false)
            ->assertSee('data-dispatch-phase="2"', false)
            ->assertSee('id="dispatchPreviewCanvas"', false);

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/despachos/contenedores?query='.$containerCode)
            ->assertOk()
            ->assertJsonPath('0.estatus_pyp', 'Asignado')
            ->assertJsonPath('0.auditoria_aprobada', false)
            ->assertJsonPath('0.puede_despachar', false);

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->post('/despachos', ['contenedor_id' => $containerId] + $ingressData)
            ->assertRedirect()
            ->assertSessionHasErrors('dispatch');
        $this->assertSame(1, (int) DB::table('contenedores')->where('id', $containerId)->value('estado_id'));

        DB::table('pyp_auditoria')->where('contenedor_id', $containerId)->where('estado_id', 1)->update(['external_audit_company_id' => $auditCompanyId]);
        $this->actingAs($pyp)
            ->withSession(['sucursal_activa' => $branchId])
            ->get("/seguimiento-pyp?patio_id={$patioId}&bloque_id={$destinationBlockId}")
            ->assertOk()
            ->assertSee('Auditoría externa')
            ->assertSee('data-pyp-dialog="info"', false);

        $revision = fn (): int => (int) DB::table('container_assignment_events')->where('container_id', $containerId)->max('id');
        $this->post(route('pyp-audits.begin', $containerId), ['audit_version' => $revision()])->assertSessionHasNoErrors();
        $this->patch("/contenedores/{$containerId}/pyp", [
            'audit_version' => $revision(), 'estatus_pyp' => 'Rechazado',
            'informacion_adicional' => 'Se requiere reparar el sello de la puerta lateral del contenedor.',
        ])->assertSessionHasErrors('photos');

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/despachos/contenedores?query='.$containerCode)
            ->assertOk()
            ->assertJsonPath('0.estatus_pyp', 'En revision')
            ->assertJsonPath('0.puede_despachar', false);

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->post('/despachos', ['contenedor_id' => $containerId] + $ingressData)
            ->assertRedirect()
            ->assertSessionHasErrors('dispatch');

        $this->actingAs($pyp)
            ->withSession(['sucursal_activa' => $branchId])
            ->patch("/contenedores/{$containerId}/pyp", [
                'estatus_pyp' => 'Aprobado',
                'audit_version' => $revision(),
                'informacion_adicional' => 'La inspeccion confirma que el equipo cumple las condiciones operativas.',
            ])
            ->assertRedirect(route('pyp-audits.history', ['container' => $containerId]))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/despachos/contenedores?query='.$containerCode)
            ->assertOk()
            ->assertJsonPath('0.auditoria_aprobada', true)
            ->assertJsonPath('0.puede_despachar', true);

        $blockingContainer = (array) DB::table('contenedores')->where('id', $containerId)->first();
        unset($blockingContainer['id']);
        $blockingContainer['nombre'] = 'BLCK'.random_int(1000000, 9999999);
        $blockingContainer['posicion'] = 24;
        $blockingContainer['estado_id'] = 1;
        $blockerId = DB::table('contenedores')->insertGetId($blockingContainer);

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/despachos/contenedores?query='.$containerCode)
            ->assertOk()
            ->assertJsonPath('0.bloqueadores', 1)
            ->assertJsonPath('0.puede_despachar', false);

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->post('/despachos', ['contenedor_id' => $containerId] + $ingressData)
            ->assertRedirect()
            ->assertSessionHasErrors('dispatch');

        DB::table('contenedores')->where('id', $blockerId)->update(['estado_id' => 2]);

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->post('/despachos', [
                'contenedor_id' => $containerId,
                'observaciones' => 'El contenedor se entrega en las condiciones verificadas durante la inspeccion.',
            ] + $ingressData)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, (int) DB::table('contenedores')->where('id', $containerId)->value('estado_id'));

        $this->actingAs($admin)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/reportes?tipo=despachos&f_inicio='.now()->format('Y-m-d').'&f_fin='.now()->format('Y-m-d'))
            ->assertOk()
            ->assertSee($containerCode);
    }

    public function test_external_pyp_role_cannot_see_unassigned_non_available_containers(): void
    {
        $branchId = DB::table('sucursales')->insertGetId([
            'nombre' => 'QA Privacy '.uniqid(),
            'ubicacion' => 'Temporal',
            'estado_id' => 1,
        ]);
        $patioId = DB::table('patios')->insertGetId([
            'nombre' => 'QA Patio Privacy '.uniqid(),
            'sucursal_id' => $branchId,
            'estado_id' => 1,
        ]);
        $blockId = DB::table('bloques')->insertGetId([
            'numero' => 201,
            'patio_id' => $patioId,
            'capacidad_maxima' => 25,
            'capacidad_actual' => 1,
            'estado_id' => 1,
        ]);

        $catalog = $this->catalog();
        $containerCode = 'MASK'.random_int(1000000, 9999999);
        DB::table('contenedores')->insert([
            'nombre' => $containerCode,
            'posicion' => 25,
            'bloque_id' => $blockId,
            'tipo_unidad_id' => $catalog['type_id'],
            'modelo_id' => $catalog['model_id'],
            'estatus_unidad_id' => $catalog['unit_status_id'],
            'observaciones_unidad' => 'Privado',
            'estatus_box_id' => $catalog['box_status_id'],
            'observaciones_box' => 'Privado',
            'estatus_ipsa_id' => 2,
            'estado_id' => 1,
            'fecha_ingreso' => now(),
        ]);

        $pyp = $this->createUser($branchId, 5, 'privacy_pyp');
        $this->configureExternalUser($pyp, $branchId);
        $foreignCompany = DB::table('external_audit_companies')->insertGetId(['name' => 'QA Auditora ajena '.uniqid(), 'name_key' => 'qa auditora ajena '.uniqid(), 'status' => 1]);
        $foreignCode = 'PRIV'.random_int(1000000, 9999999);
        $original = (array) DB::table('contenedores')->where('nombre', $containerCode)->first();
        unset($original['id']);
        $foreignContainer = DB::table('contenedores')->insertGetId(array_replace($original, ['nombre' => $foreignCode, 'posicion' => 24]));
        DB::table('pyp_auditoria')->insert(['contenedor_id' => $foreignContainer, 'external_audit_company_id' => $foreignCompany, 'estatus_pyp' => 'Asignado', 'usuario_id' => $pyp->id, 'estado_id' => 1]);

        $this->actingAs($pyp)
            ->withSession(['sucursal_activa' => $branchId])
            ->get("/seguimiento-pyp?patio_id={$patioId}&bloque_id={$blockId}")
            ->assertOk()
            ->assertDontSee('XXXXXXXXXXX')
            ->assertDontSee($containerCode)
            ->assertDontSee($foreignCode);

        $this->actingAs($pyp)
            ->withSession(['sucursal_activa' => $branchId])
            ->get("/seguimiento-pyp?search={$containerCode}")
            ->assertOk()
            ->assertSee('0 contenedores encontrados.')
            ->assertDontSee('data-code="'.$containerCode.'"', false);

        Livewire::test(InventoryTable::class)
            ->set('patioId', (string) $patioId)->set('blockId', (string) $blockId)->set('viewMode', 'yard3d')
            ->assertDontSee($foreignCode)->assertDontSee($containerCode);
    }

    private function configureExternalUser(User $user, int $branchId): int
    {
        $companyId = DB::table('external_audit_companies')->insertGetId(['name' => 'QA Auditora '.uniqid(), 'name_key' => 'qa auditora '.uniqid(), 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $user->update(['external_audit_company_id' => $companyId, 'external_access_level' => 'inspector']);
        $user->authorizedExternalBranches()->sync([$branchId]);

        return $companyId;
    }

    private function createUser(int $branchId, int $roleId, string $prefix): User
    {
        $id = DB::table('users')->insertGetId([
            'username' => $prefix.'_qa_'.uniqid(),
            'password' => Hash::make('secret'),
            'email' => $prefix.'_qa_'.uniqid().'@example.test',
            'rol_id' => $roleId,
            'sucursal_id' => $branchId,
            'estado_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    private function catalog(): array
    {
        $typeId = (int) DB::table('tipos_unidad')->where('estado_id', 1)->orderBy('id')->value('id');
        $modelId = (int) DB::table('modelos')->where('tipo_unidad_id', $typeId)->where('estado_id', 1)->orderBy('id')->value('id');
        $unitStatusId = (int) DB::table('estatus_contenedores')->where('tipo_estatus_id', 1)->where('estado_id', 1)->orderBy('id')->value('id');
        $boxStatusId = (int) DB::table('estatus_contenedores')->where('tipo_estatus_id', 2)->where('estado_id', 1)->orderBy('id')->value('id');
        $availableIpsaId = (int) DB::table('estatus_ipsa')->where('nombre', 'Disponible')->where('estado_id', 1)->value('id');

        $this->assertGreaterThan(0, $typeId, 'No hay tipos de unidad activos para la prueba.');
        $this->assertGreaterThan(0, $modelId, 'No hay modelos activos para la prueba.');
        $this->assertGreaterThan(0, $unitStatusId, 'No hay estatus de unidad activos para la prueba.');
        $this->assertGreaterThan(0, $boxStatusId, 'No hay estatus de box activos para la prueba.');
        $this->assertGreaterThan(0, $availableIpsaId, 'No hay estatus IPSA Disponible activo para la prueba.');

        return compact('typeId', 'modelId') + [
            'type_id' => $typeId,
            'model_id' => $modelId,
            'unit_status_id' => $unitStatusId,
            'box_status_id' => $boxStatusId,
            'available_ipsa_id' => $availableIpsaId,
        ];
    }
}
