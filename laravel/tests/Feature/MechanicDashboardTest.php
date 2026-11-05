<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MaintenanceWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MechanicDashboardTest extends TestCase
{
    private int $branch;

    private int $block;

    private int $line;

    private int $oosStatus;

    private User $mechanic;

    private User $otherMechanic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedMysql();
        DB::beginTransaction();

        $this->branch = DB::table('sucursales')->insertGetId(['nombre' => 'QA Taller '.uniqid(), 'ubicacion' => 'Temporal', 'estado_id' => 1]);
        $yard = DB::table('patios')->insertGetId(['nombre' => 'Patio taller', 'sucursal_id' => $this->branch, 'estado_id' => 1]);
        $this->block = DB::table('bloques')->insertGetId(['numero' => 7, 'patio_id' => $yard, 'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1]);
        $this->line = DB::table('shipping_lines')->insertGetId(['name' => 'Naviera taller', 'name_key' => uniqid(), 'status' => 1]);
        $this->oosStatus = (int) app(MaintenanceWorkflow::class)->outOfServiceStatuses()->value('id');
        $this->assertGreaterThan(0, $this->oosStatus);
        $this->mechanic = $this->user(4, 'mechanic');
        $this->otherMechanic = $this->user(4, 'other');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_mechanic_dashboard_counts_only_their_jobs_and_lists_corrections_first(): void
    {
        $started = $this->job($this->container(1), $this->mechanic, 'in_progress', now()->subDays(3));
        $correction = $this->job($this->container(2), $this->mechanic, 'returned_for_repair', now()->subDay());
        $this->job($this->container(3), $this->mechanic, 'pending_review', now());
        $this->job($this->container(4), $this->mechanic, 'completed', now());
        $this->job($this->container(5), $this->otherMechanic, 'in_progress', now()->subDays(5));
        DB::table('maintenance_events')->insert(['job_id' => $correction, 'user_id' => $this->otherMechanic->id,
            'action' => 'correction_requested', 'after_data' => '{}', 'created_at' => now()]);

        $this->actingAs($this->mechanic)->withSession(['sucursal_activa' => $this->branch])->get('/')
            ->assertOk()
            ->assertViewIs('dashboard.mechanic')
            ->assertViewHas('counts', ['in_progress' => 1, 'returned_for_repair' => 1, 'pending_review' => 1, 'completed_this_month' => 1])
            ->assertViewHas('total', 4)
            ->assertViewHas('nextJobs', fn ($jobs): bool => $jobs->pluck('id')->all() === [$correction, $started])
            ->assertViewHas('activity', fn ($events): bool => $events->pluck('action')->all() === ['correction_requested'])
            ->assertSee(route('corrections.show', $correction), false)
            ->assertSee(route('maintenance.show', $started), false)
            ->assertSee('La auditora pidió una corrección');
    }

    public function test_waiting_containers_only_include_repairable_units_without_open_work(): void
    {
        $waiting = $this->container(1);
        $this->job($this->container(2), $this->otherMechanic, 'in_progress', now());

        $this->actingAs($this->mechanic)->withSession(['sucursal_activa' => $this->branch])->get('/')
            ->assertOk()
            ->assertViewHas('waitingCount', 1)
            ->assertViewHas('waitingContainers', fn ($containers): bool => $containers->pluck('id')->all() === [$waiting])
            ->assertSee(route('maintenance.container', $waiting), false);
    }

    public function test_internal_roles_keep_the_operational_dashboard(): void
    {
        $this->actingAs($this->user(2, 'admin'))->withSession(['sucursal_activa' => $this->branch])->get('/')
            ->assertOk()
            ->assertViewIs('dashboard.index');
    }

    private function user(int $role, string $key): User
    {
        return User::create(['username' => 'QA_'.$key.'_'.uniqid(), 'email' => uniqid().'@example.test',
            'password' => Hash::make('Secret123!'), 'rol_id' => $role, 'sucursal_id' => $this->branch, 'estado_id' => 1]);
    }

    private function container(int $position): int
    {
        $type = DB::table('tipos_unidad')->where('estado_id', 1)->value('id');
        $ok = DB::table('estatus_contenedores')->insertGetId(['nombre' => 'OK QA '.uniqid(), 'tipo_estatus_id' => 1, 'estado_id' => 1]);
        $container = DB::table('contenedores')->insertGetId(['nombre' => 'MCHU'.random_int(1000000, 9999999),
            'tipo_unidad_id' => $type, 'modelo_id' => DB::table('modelos')->where('tipo_unidad_id', $type)->value('id'),
            'estatus_unidad_id' => $ok, 'estatus_box_id' => $ok, 'estatus_ipsa_id' => $this->oosStatus,
            'bloque_id' => $this->block, 'posicion' => $position, 'fecha_ingreso' => now()->subDays(10 - $position), 'estado_id' => 1]);
        $cycle = DB::table('container_ingress_cycles')->insertGetId(['container_id' => $container, 'branch_id' => $this->branch,
            'shipping_line_id' => $this->line, 'shipping_line_name' => 'Naviera taller', 'entered_at' => now(),
            'created_by' => $this->mechanic->id ?? 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('contenedores')->where('id', $container)->update(['current_ingress_cycle_id' => $cycle]);

        return $container;
    }

    private function job(int $container, User $mechanic, string $status, mixed $updatedAt): int
    {
        return DB::table('maintenance_jobs')->insertGetId(['container_id' => $container, 'branch_id' => $this->branch,
            'mechanic_id' => $mechanic->id, 'ingress_cycle_id' => DB::table('contenedores')->where('id', $container)->value('current_ingress_cycle_id'),
            'status' => $status, 'diagnosis' => 'Diagnóstico de prueba del taller', 'created_at' => $updatedAt, 'updated_at' => $updatedAt]);
    }
}
