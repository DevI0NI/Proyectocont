<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class RouteAccessTest extends TestCase
{
    public function test_guest_is_redirected_from_protected_routes(): void
    {
        $this->get('/contenedores')->assertRedirect('/login');
        $this->get('/seguimiento-pyp')->assertRedirect('/login');
        $this->get('/reportes')->assertRedirect('/login');
        $this->get('/traslados')->assertRedirect('/login');
    }

    public function test_role_restricted_routes_return_forbidden_before_controller_logic(): void
    {
        $this->actingAs($this->userWithRole(4))
            ->get('/ingresos')
            ->assertForbidden();

        $this->actingAs($this->userWithRole(5))
            ->get('/usuarios')
            ->assertForbidden();

        $this->actingAs($this->userWithRole(3))
            ->get('/parametros')
            ->assertForbidden();
    }

    public function test_main_migrated_route_names_generate_expected_paths(): void
    {
        $this->assertSame('http://localhost:8000/contenedores', route('containers.index'));
        $this->assertSame('http://localhost:8000/seguimiento-pyp', route('pyp-audits.index'));
        $this->assertSame('http://localhost:8000/traslados', route('transfers.create'));
        $this->assertSame('http://localhost:8000/despachos', route('dispatches.create'));
        $this->assertSame('http://localhost:8000/reportes', route('reports.index'));
        $this->assertSame('http://localhost:8000/parametros', route('parameters.index'));
        $this->assertSame('http://localhost:8000/rentas', route('rentals.index'));
        $this->assertSame('http://localhost:8000/rentas/espacios', route('rentals.spaces'));
        $this->assertSame('http://localhost:8000/mantenimiento/ordenes', route('maintenance.orders'));
    }

    public function test_legacy_screen_urls_redirect_to_migrated_routes(): void
    {
        $this->actingAs($this->userWithRole(1))
            ->get('/index.php')
            ->assertRedirect('/');

        $this->actingAs($this->userWithRole(1))
            ->get('/bloques/bloques.php')
            ->assertRedirect('/contenedores');

        $this->actingAs($this->userWithRole(1))
            ->get('/ingresos/ingresos.php')
            ->assertRedirect('/ingresos');

        $this->actingAs($this->userWithRole(1))
            ->get('/traslados/traslados.php')
            ->assertRedirect('/traslados');

        $this->actingAs($this->userWithRole(1))
            ->get('/despachos/despachos.php')
            ->assertRedirect('/despachos');

        $this->actingAs($this->userWithRole(1))
            ->get('/reportes/reportes.php')
            ->assertRedirect('/reportes');

        $this->actingAs($this->userWithRole(1))
            ->get('/parametros/parametros.php')
            ->assertRedirect('/parametros');
    }

    private function userWithRole(int $roleId): User
    {
        $user = new User([
            'username' => "role{$roleId}",
            'email' => "role{$roleId}@example.test",
            'rol_id' => $roleId,
            'sucursal_id' => 1,
            'estado_id' => 1,
        ]);

        $user->id = 999 + $roleId;

        return $user;
    }
}
