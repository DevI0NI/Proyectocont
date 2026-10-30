<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemonstrationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class DemonstrationSeederTest extends TestCase
{
    private bool $integrationTransaction = false;

    protected function tearDown(): void
    {
        if ($this->integrationTransaction && DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_refuses_the_application_database_before_reading_or_writing_records(): void
    {
        DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('connection->getDatabaseName')->once()->andReturn('proyectocont_v2.1');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('proportsa_demo_');
        app(DemonstrationSeeder::class)->run();
    }

    public function test_demo_scenarios_preserve_accounts_and_generate_consistent_histories_without_delivery(): void
    {
        $database = env('DEMO_TEST_DATABASE');
        if (! is_string($database) || ! preg_match('/^proportsa_demo_[a-z0-9_]+$/', $database)) {
            $this->markTestSkipped('Define DEMO_TEST_DATABASE con una copia vacia y aislada de demostracion.');
        }
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database,
            'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => '', 'cache.default' => 'array']);
        DB::purge('mysql');
        $this->assertSame(0, DB::table('contenedores')->count());
        $accounts = DB::table('users')->orderBy('id')->get()->map(fn ($user): array => (array) $user)->all();
        $catalogs = [];
        foreach (['sucursales', 'roles', 'estatus_contenedores', 'estatus_ipsa', 'container_iso_codes', 'repair_services', 'repair_parts'] as $table) {
            $catalogs[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        Mail::fake();
        Queue::fake();
        DB::beginTransaction();
        $this->integrationTransaction = true;
        app(DemonstrationSeeder::class)->run();
        foreach ($accounts as $account) {
            $this->assertSame($account, (array) DB::table('users')->find($account['id']));
        }
        foreach ($catalogs as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $branches = DB::table('sucursales')->where('estado_id', 1)->count();
        $this->assertSame($branches * 40, DB::table('contenedores')->count());
        $this->assertGreaterThan($branches * 40, DB::table('container_ingress_cycles')->count());
        $this->assertSame($branches * 3, DB::table('patios')->count());
        $this->assertSame($branches * 9, DB::table('bloques')->count());
        $this->assertSame(2025, (int) substr(DB::table('container_ingress_cycles')->min('entered_at'), 0, 4));
        $this->assertSame(2026, (int) substr(DB::table('container_ingress_cycles')->max('entered_at'), 0, 4));
        $this->assertLessThanOrEqual(now()->toDateTimeString(), DB::table('container_assignment_events')->max('created_at'));
        foreach ([2025, 2026] as $year) {
            $this->assertGreaterThan(0, DB::table('container_assignment_events')->where('event_type', 'dispatch')->whereYear('created_at', $year)->count());
        }
        foreach ([2, 3] as $cycles) {
            $this->assertGreaterThan(0, DB::table('container_ingress_cycles')->select('container_id')->groupBy('container_id')->havingRaw('COUNT(*) = ?', [$cycles])->get()->count());
        }
        $this->assertGreaterThan(0, DB::table('contenedores as c')->where('c.estado_id', 1)->whereNotExists(fn ($query) => $query->selectRaw('1')
            ->from('maintenance_jobs as j')->whereColumn('j.container_id', 'c.id'))->count());
        foreach ([2, 3, 4, 5] as $role) {
            $this->assertGreaterThanOrEqual(3, DB::table('users')->where('email', 'like', 'demo.%@example.test')->where('rol_id', $role)->count());
        }
        $this->assertSame(3, DB::table('tipos_unidad')->count());
        $this->assertSame(6, DB::table('modelos')->count());
        $this->assertSame(3, DB::table('shipping_lines')->count());
        $this->assertSame(3, DB::table('external_audit_companies')->count());
        $this->assertSame($branches * 3, DB::table('shipping_line_branch_auditor')->count());
        foreach (['in_progress', 'pending_review', 'returned_for_repair', 'completed'] as $status) {
            $this->assertGreaterThan(0, DB::table('maintenance_jobs')->where('status', $status)->count());
        }
        foreach (['draft', 'submitted', 'approved'] as $status) {
            $this->assertGreaterThan(0, DB::table('maintenance_corrections')->where('status', $status)->count());
        }
        $this->assertSame(DB::table('container_ingress_cycles')->whereNotNull('closed_at')->count(), DB::table('container_assignment_events')->where('event_type', 'dispatch')->count());
        $this->assertSame($branches * 25, DB::table('logs_traslados')->whereNotNull('bloque_anterior_id')->count());
        $this->assertSame(0, DB::table('contenedores as c')->join('modelos as m', 'm.id', '=', 'c.modelo_id')->whereColumn('c.tipo_unidad_id', '!=', 'm.tipo_unidad_id')->count());
        $this->assertSame(0, DB::table('contenedores')->where('estado_id', 1)->select('bloque_id', 'posicion')->groupBy('bloque_id', 'posicion')->havingRaw('COUNT(*) > 1')->get()->count());
        foreach (DB::table('bloques')->get() as $block) {
            $this->assertSame((int) $block->capacidad_actual, DB::table('contenedores')->where('bloque_id', $block->id)->where('estado_id', 1)->count());
            $this->assertGreaterThan(0, (int) $block->capacidad_actual);
        }
        foreach (DB::table('container_ingress_cycles')->get() as $cycle) {
            $this->assertNotNull(DB::table('container_assignment_events')->where('ingress_cycle_id', $cycle->id)->whereIn('event_type', ['ingress', 'reentry'])->first());
            if ($cycle->closed_at) {
                $this->assertGreaterThanOrEqual($cycle->entered_at, $cycle->closed_at);
            }
        }
        foreach (DB::table('container_ingress_cycles')->select('container_id')->groupBy('container_id')->havingRaw('COUNT(*) > 1')->pluck('container_id') as $containerId) {
            $previousExit = null;
            foreach (DB::table('container_ingress_cycles')->where('container_id', $containerId)->orderBy('entered_at')->get() as $cycle) {
                if ($previousExit !== null) {
                    $this->assertGreaterThan($previousExit, $cycle->entered_at);
                }
                $previousExit = $cycle->closed_at;
                $this->assertSame(1, DB::table('container_assignment_events')->where('ingress_cycle_id', $cycle->id)->whereIn('event_type', ['ingress', 'reentry'])->count());
            }
        }
        $root = storage_path('app/demo/'.$database.'/');
        foreach (DB::table('maintenance_evidence')->get() as $evidence) {
            $this->assertFileExists($root.$evidence->path);
            $this->assertLessThanOrEqual(2097152, filesize($root.$evidence->path));
        }
        foreach (DB::table('container_assignment_events')->where('event_type', 'external_audit_status')->get() as $event) {
            $audit = json_decode($event->after_data, true)['audit'];
            foreach ($audit['photos'] ?? [] as $photo) {
                $this->assertFileExists($root.$photo['path']);
            }
        }
        $this->actingAs(User::where('rol_id', 1)->first())->withSession(['sucursal_activa' => DB::table('sucursales')->min('id')]);
        $event = DB::table('container_assignment_events')->where('event_type', 'dispatch')->first();
        $this->get(route('documents.dispatch.eir', ['event_id' => $event->id]))->assertOk();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vacia de operaciones');
        app(DemonstrationSeeder::class)->run();
    }
}
