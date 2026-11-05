<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\Overview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardParityTest extends TestCase
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

    public function test_dashboard_routes_keep_mechanics_and_external_auditors_in_their_own_workspaces(): void
    {
        $branchId = $this->branchWithTwoEqualCapacityBlocks();
        $mechanic = $this->createUser($branchId, 4, 'mechanic');
        $pyp = $this->createUser($branchId, 5, 'pyp_dash');

        $this->actingAs($mechanic)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/')
            ->assertOk()
            ->assertViewIs('dashboard.mechanic');

        $this->actingAs($mechanic)
            ->withSession(['sucursal_activa' => $branchId])
            ->get(route('containers.index'))
            ->assertRedirect(route('maintenance.orders'));

        $this->actingAs($pyp)
            ->withSession(['sucursal_activa' => $branchId])
            ->get('/')
            ->assertForbidden();

        $company = DB::table('external_audit_companies')->insertGetId(['name' => 'Auditora dashboard QA', 'name_key' => uniqid(), 'status' => 1]);
        $pyp->update(['external_audit_company_id' => $company, 'external_access_level' => 'inspector']);
        $pyp->authorizedExternalBranches()->sync([$branchId]);
        $this->actingAs($pyp)->get('/')->assertRedirect(route('pyp-audits.index'));
    }

    public function test_internal_dashboard_preserves_the_capacity_of_both_blocks(): void
    {
        $branchId = $this->branchWithTwoEqualCapacityBlocks();
        $admin = $this->createUser($branchId, 2, 'admin_dash');
        $this->actingAs($admin)->withSession(['sucursal_activa' => $branchId])->get('/')
            ->assertOk()->assertSee('de 50 espacios');
        Livewire::test(Overview::class)->assertViewHas('stats', fn (array $stats): bool => $stats['capacidad'] === 50);
    }

    private function branchWithTwoEqualCapacityBlocks(): int
    {
        $branchId = DB::table('sucursales')->insertGetId([
            'nombre' => 'QA Dashboard '.uniqid(),
            'ubicacion' => 'Temporal',
            'estado_id' => 1,
        ]);

        $patioId = DB::table('patios')->insertGetId([
            'nombre' => 'QA Dashboard Patio '.uniqid(),
            'sucursal_id' => $branchId,
            'estado_id' => 1,
        ]);

        foreach ([301, 302] as $number) {
            DB::table('bloques')->insert([
                'numero' => $number,
                'patio_id' => $patioId,
                'capacidad_maxima' => 25,
                'capacidad_actual' => 0,
                'estado_id' => 1,
            ]);
        }

        return $branchId;
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
}
