<?php

namespace Tests\Feature;

use App\Models\Bloque;
use App\Models\User;
use App\Services\DispatchService;
use App\Services\IngresoService;
use App\Services\TransferService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BlockOccupancyTest extends TestCase
{
    private User $user;

    private Bloque $origin;

    private Bloque $destination;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedMysql();
        DB::beginTransaction();
        Mail::fake();
        Queue::fake();
        $this->user = User::where('email', 'demo.admins.1@example.test')->firstOrFail();
        $yard = DB::table('patios')->where('sucursal_id', $this->user->sucursal_id)->where('estado_id', 1)->value('id');
        $number = (int) DB::table('bloques')->where('patio_id', $yard)->max('numero') + 1;
        foreach (['origin', 'destination'] as $offset => $property) {
            $this->{$property} = Bloque::create(['patio_id' => $yard, 'numero' => $number + $offset,
                'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1]);
        }
        $sample = DB::table('contenedores as c')->join('container_ingress_cycles as cycle', 'cycle.id', '=', 'c.current_ingress_cycle_id')
            ->where('cycle.branch_id', $this->user->sucursal_id)->whereNotNull('cycle.audit_company_id')
            ->select('c.*', 'cycle.shipping_line_id', 'cycle.audit_company_id')->first();
        $this->assertNotNull($sample);
        $this->payload = ['fecha_ingreso' => now()->toDateString(), 'tipo_unidad' => $sample->tipo_unidad_id,
            'modelo' => $sample->modelo_id, 'iso_code' => $sample->iso_code, 'marca' => 'CIMC',
            'estado_unidad' => $sample->estatus_unidad_id, 'status_box' => $sample->estatus_box_id,
            'estatus_ipsa' => DB::table('estatus_ipsa')->where('nombre', 'Disponible')->value('id'),
            'transportista' => 'Transporte de prueba', 'conductor' => 'Piloto de prueba',
            'placa_cabezal' => 'C123ABC', 'placa_chasis' => 'TC123ABC',
            'shipping_line_id' => $sample->shipping_line_id, 'audit_company_id' => $sample->audit_company_id, 'posicion' => 1];
        $this->actingAs($this->user)->withSession(['sucursal_activa' => $this->user->sucursal_id]);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_ingress_transfer_dispatch_and_reentry_count_each_active_container_once(): void
    {
        $code = 'OCCU'.random_int(1000000, 9999999);
        $result = app(IngresoService::class)->register($this->user, $this->payload, $code, $this->origin);
        $id = $result['container_id'];
        $this->assertSame(1, $this->origin->fresh()->capacidad_actual);
        app(TransferService::class)->transfer($this->user, $this->user->sucursal_id, [
            'contenedor_id' => $id, 'nuevo_bloque_id' => $this->destination->id, 'nueva_posicion' => 1]);
        $this->assertSame(0, $this->origin->fresh()->capacidad_actual);
        $this->assertSame(1, $this->destination->fresh()->capacidad_actual);
        DB::table('pyp_auditoria')->where('contenedor_id', $id)->where('estado_id', 1)->update(['estatus_pyp' => 'Aprobado']);
        app(DispatchService::class)->dispatch($this->user, $this->user->sucursal_id, ['contenedor_id' => $id]);
        $this->assertSame(0, $this->destination->fresh()->capacidad_actual);
        $result = app(IngresoService::class)->register($this->user, $this->payload, $code, $this->origin);
        $this->assertTrue($result['was_reentry']);
        $this->assertSame(1, $this->origin->fresh()->capacidad_actual);
        $this->assertSame(0, $this->destination->fresh()->capacidad_actual);
    }

    public function test_last_free_place_is_usable_and_an_additional_ingress_is_rejected(): void
    {
        $this->origin->update(['capacidad_maxima' => 1]);
        app(IngresoService::class)->register($this->user, $this->payload, 'OCCU'.random_int(1000000, 9999999), $this->origin);
        $this->assertSame(1, $this->origin->fresh()->capacidad_actual);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lleno');
        app(IngresoService::class)->register($this->user, array_replace($this->payload, ['posicion' => 2]),
            'OCCU'.random_int(1000000, 9999999), $this->origin);
    }

    public function test_failed_ingress_does_not_change_occupancy_or_create_a_second_cycle(): void
    {
        $code = 'OCCU'.random_int(1000000, 9999999);
        $result = app(IngresoService::class)->register($this->user, $this->payload, $code, $this->origin);
        try {
            app(IngresoService::class)->register($this->user, array_replace($this->payload, ['posicion' => 2]), $code, $this->origin);
            $this->fail('An active container must not be registered twice.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('activo', $exception->getMessage());
        }
        $this->assertSame(1, $this->origin->fresh()->capacidad_actual);
        $this->assertSame(1, DB::table('container_ingress_cycles')->where('container_id', $result['container_id'])->count());

    }
}
