<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccessDeniedNavigationTest extends TestCase
{
    private int $branch;

    private User $mechanic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useIsolatedMysql();
        DB::beginTransaction();

        $this->branch = DB::table('sucursales')->insertGetId(['nombre' => 'QA Acceso '.uniqid(), 'ubicacion' => 'Temporal', 'estado_id' => 1]);
        $this->mechanic = User::create(['username' => 'QA_acceso_'.uniqid(), 'email' => uniqid().'@example.test',
            'password' => Hash::make('Secret123!'), 'rol_id' => 4, 'sucursal_id' => $this->branch, 'estado_id' => 1]);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_typed_forbidden_url_returns_to_the_previous_page_with_a_notice(): void
    {
        $previous = url('/mantenimiento/ordenes?patio_id=2');

        $this->actingAs($this->mechanic)->withSession(['sucursal_activa' => $this->branch])
            ->get('/despachos', ['Sec-Fetch-Mode' => 'navigate', 'Referer' => $previous])
            ->assertRedirect($previous)
            ->assertSessionHas('access_denied', 'No tienes permiso para entrar a esa sección.');
    }

    public function test_forbidden_url_without_a_previous_page_goes_to_the_dashboard(): void
    {
        $this->actingAs($this->mechanic)->withSession(['sucursal_activa' => $this->branch])
            ->get('/despachos', ['Sec-Fetch-Mode' => 'navigate', 'Referer' => 'https://otro-sitio.test/pagina'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('access_denied');
    }

    public function test_dashboard_shows_the_access_notice(): void
    {
        $this->actingAs($this->mechanic)->withSession(['sucursal_activa' => $this->branch, 'access_denied' => 'No tienes permiso para entrar a esa sección.'])
            ->get('/')
            ->assertOk()
            ->assertSee('toast-denied', false)
            ->assertSee('Sin acceso')
            ->assertSee('No tienes permiso para entrar a esa sección.');
    }

    public function test_requests_that_are_not_page_navigation_keep_the_error_status(): void
    {
        $this->actingAs($this->mechanic)->withSession(['sucursal_activa' => $this->branch])
            ->get('/despachos')
            ->assertForbidden()
            ->assertSee('No tienes acceso a esta sección')
            ->assertSee('Esta vista no pertenece a la interfaz de mantenimiento.');
    }

    public function test_unknown_page_shows_the_branded_not_found_page(): void
    {
        $this->get('/pagina-que-no-existe', ['Sec-Fetch-Mode' => 'navigate'])
            ->assertNotFound()
            ->assertSee('No encontramos esta página')
            ->assertSee('Ir al inicio');
    }
}
