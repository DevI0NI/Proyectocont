<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchManagementController;
use App\Http\Controllers\ContainerInventoryController;
use App\Http\Controllers\ContainerStatusController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\IngresoController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\OperationalCatalogController;
use App\Http\Controllers\ParameterController;
use App\Http\Controllers\PypAuditController;
use App\Http\Controllers\RentalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UnitTypeController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\YardLayoutController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::redirect('/login.php', '/login', 302);
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    Route::post('/authenticate.php', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::redirect('/index.php', '/', 302);
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/logout.php', [AuthController::class, 'legacyLogout']);
    Route::post('/sucursal-activa', [BranchController::class, 'update'])
        ->middleware('role:1,5')
        ->name('branches.active.update');
    Route::post('/cambiar_sucursal.php', [BranchController::class, 'update'])
        ->middleware('role:1,5');

    Route::resource('usuarios', UserManagementController::class)
        ->parameters(['usuarios' => 'user'])
        ->names('users')
        ->middleware('role:1,2')
        ->except(['show']);

    Route::patch('/sucursales/{branch}/reactivar', [BranchManagementController::class, 'reactivate'])
        ->middleware('role:1')
        ->name('branches.reactivate');
    Route::resource('sucursales', BranchManagementController::class)
        ->parameters(['sucursales' => 'branch'])
        ->names('branches')
        ->middleware('role:1')
        ->except(['show']);

    Route::resource('tipos-unidad', UnitTypeController::class)
        ->parameters(['tipos-unidad' => 'unitType'])
        ->names('unit-types')
        ->middleware('role:1,2')
        ->except(['show']);

    Route::patch('/estatus/{status}/reactivar', [ContainerStatusController::class, 'reactivate'])->middleware('role:1,2')->name('statuses.reactivate');
    Route::resource('estatus', ContainerStatusController::class)
        ->parameters(['estatus' => 'status'])
        ->names('statuses')
        ->middleware('role:1,2')
        ->except(['show']);

    Route::middleware('role:1,2')->group(function (): void {
        Route::get('/catalogos', [OperationalCatalogController::class, 'overview'])->name('catalogs.overview');
        Route::any('/parametros/catalogos/{catalog}/{path?}', function (Request $request, string $catalog, ?string $path = null) {
            $target = '/catalogos/'.$catalog.($path ? '/'.$path : '');
            if ($request->getQueryString()) {
                $target .= '?'.$request->getQueryString();
            }

            return redirect($target, 307);
        })->where(['catalog' => 'navieras|auditoras|iso|repuestos|reparaciones', 'path' => '.*']);
        Route::prefix('catalogos/{catalog}')
            ->where(['catalog' => 'navieras|auditoras|iso|repuestos|reparaciones'])
            ->name('catalogs.')->group(function (): void {
                Route::get('/', [OperationalCatalogController::class, 'index'])->name('index');
                Route::get('/nuevo', [OperationalCatalogController::class, 'create'])->name('create');
                Route::post('/', [OperationalCatalogController::class, 'store'])->name('store');
                Route::get('/{record}/editar', [OperationalCatalogController::class, 'edit'])->whereNumber('record')->name('edit');
                Route::patch('/{record}', [OperationalCatalogController::class, 'update'])->whereNumber('record')->name('update');
                Route::patch('/{record}/estado', [OperationalCatalogController::class, 'status'])->whereNumber('record')->name('status');
            });
        Route::get('/parametros', [ParameterController::class, 'index'])->name('parameters.index');
        Route::redirect('/parametros/parametros.php', '/parametros', 302);

        Route::redirect('/parametros/usuarios/usuarios.php', '/usuarios', 302);
        Route::redirect('/parametros/usuarios/usuarios_inactivos.php', '/usuarios', 302);
        Route::post('/parametros/usuarios/verificar_usuario.php', [UserManagementController::class, 'legacyCheck'])->name('users.check');
        Route::redirect('/parametros/sucursales/sucursales.php', '/sucursales', 302)->middleware('role:1');
        Route::post('/parametros/sucursales/verificar_sucursal.php', [BranchManagementController::class, 'legacyCheck'])->middleware('role:1');
        Route::redirect('/parametros/tiposunidad/tipos_unidad.php', '/tipos-unidad', 302);
        Route::post('/parametros/tiposunidad/verificar_nombre.php', [UnitTypeController::class, 'legacyCheckName']);
        Route::redirect('/parametros/estados/estados.php', '/estatus', 302);
        Route::post('/parametros/estados/verificar_estatus.php', [ContainerStatusController::class, 'legacyCheckName']);
        Route::post('/parametros/estados/verificar_nombre_estatus.php', [ContainerStatusController::class, 'legacyCheckNameWithState']);
        Route::redirect('/parametros/bloques/bloques.php', '/patios-bloques', 302);
        Route::redirect('/parametros/bloques/configuracion.php', '/patios-bloques', 302);
        Route::get('/patios-bloques', [YardLayoutController::class, 'index'])->name('yard-layout.index');
        Route::get('/patios-bloques/patios/{patio}/bloques', [YardLayoutController::class, 'blocks'])->name('yard-layout.blocks');
        Route::post('/patios-bloques/patios', [YardLayoutController::class, 'storePatio'])->name('yard-layout.patios.store');
        Route::patch('/patios-bloques/patios/{patio}', [YardLayoutController::class, 'updatePatio'])->name('yard-layout.patios.update');
        Route::delete('/patios-bloques/patios/{patio}', [YardLayoutController::class, 'destroyPatio'])->name('yard-layout.patios.destroy');
        Route::post('/patios-bloques/bloques', [YardLayoutController::class, 'storeBlock'])->name('yard-layout.blocks.store');
        Route::patch('/patios-bloques/bloques/{block}', [YardLayoutController::class, 'updateBlock'])->name('yard-layout.blocks.update');
        Route::delete('/patios-bloques/bloques/{block}', [YardLayoutController::class, 'destroyBlock'])->name('yard-layout.blocks.destroy');
    });

    Route::middleware('role:1,2,3')->group(function (): void {
        Route::get('/ingresos', [IngresoController::class, 'create'])->name('ingresos.create');
        Route::redirect('/ingresos/ingresos.php', '/ingresos', 302);
        Route::post('/ingresos', [IngresoController::class, 'store'])->name('ingresos.store');
        Route::get('/ingresos/modelos/{type}', [IngresoController::class, 'modelsByType'])->name('ingresos.models');
        Route::get('/ingresos/bloques/{patio}', [IngresoController::class, 'blocksByPatio'])->name('ingresos.blocks');
        Route::get('/ingresos/posicion/{block}', [IngresoController::class, 'nextPosition'])->name('ingresos.position');
        Route::get('/ingresos/bloque-preview/{block}', [IngresoController::class, 'blockPreview'])->name('ingresos.block-preview');
        Route::get('/ingresos/verificar/{code}', [IngresoController::class, 'checkContainer'])->name('ingresos.check');
        Route::get('/ingresos/obtener_modelos.php', [IngresoController::class, 'legacyModels']);
        Route::get('/ingresos/obtener_bloques.php', [IngresoController::class, 'legacyBlocks']);
        Route::get('/ingresos/obtener_posicion.php', [IngresoController::class, 'legacyPosition']);
        Route::post('/ingresos/verificar_contenedor.php', [IngresoController::class, 'legacyCheckContainer']);

        Route::get('/traslados', [TransferController::class, 'create'])->name('transfers.create');
        Route::redirect('/traslados/traslados.php', '/traslados', 302);
        Route::post('/traslados', [TransferController::class, 'store'])->name('transfers.store');
        Route::get('/traslados/bloques/{patio}', [TransferController::class, 'blocksByPatio'])->name('transfers.blocks');
        Route::get('/traslados/buscar', [TransferController::class, 'searchContainers'])->name('transfers.search');
        Route::get('/traslados/contenedores/{block}', [TransferController::class, 'containersByBlock'])->name('transfers.containers');
        Route::get('/traslados/posicion/{block}', [TransferController::class, 'nextPosition'])->name('transfers.position');
        Route::get('/traslados/obtener_bloques_disponibles.php', [TransferController::class, 'legacyBlocks']);
        Route::get('/traslados/obtener_contenedores.php', [TransferController::class, 'legacySearchContainers']);
        Route::get('/traslados/obtener_contenedores_bloque.php', [TransferController::class, 'legacyContainersByBlock']);
        Route::get('/traslados/obtener_info_contenedor.php', [TransferController::class, 'legacyContainerInfo']);
        Route::get('/traslados/obtener_posiciones_disponibles.php', [TransferController::class, 'legacyPosition']);

        Route::get('/despachos', [DispatchController::class, 'create'])->name('dispatches.create');
        Route::redirect('/despachos/despachos.php', '/despachos', 302);
        Route::post('/despachos', [DispatchController::class, 'store'])->name('dispatches.store');
        Route::get('/despachos/contenedores', [DispatchController::class, 'search'])->name('dispatches.search');
        Route::get('/despachos/obtener_contenedores_despacho.php', [DispatchController::class, 'search']);

        Route::get('/documentos/ingreso/eir', [DocumentController::class, 'ingressEir'])->name('documents.ingress.eir');
        Route::redirect('/ingresos/eir_ingreso.php', '/documentos/ingreso/eir', 302);
        Route::get('/documentos/ingreso/ticket', [DocumentController::class, 'ingressTicket'])->name('documents.ingress.ticket');
        Route::get('/documentos/ingreso/ticket/pdf', [DocumentController::class, 'ingressTicketPdf'])->name('documents.ingress.ticket.pdf');
        Route::redirect('/ingresos/ticket_ingreso.php', '/documentos/ingreso/ticket', 302);
        Route::get('/documentos/salida/eir', [DocumentController::class, 'dispatchEir'])->name('documents.dispatch.eir');
        Route::redirect('/despachos/eir_salida.php', '/documentos/salida/eir', 302);
        Route::get('/documentos/salida/ticket', [DocumentController::class, 'dispatchTicket'])->name('documents.dispatch.ticket');
        Route::get('/documentos/salida/ticket/pdf', [DocumentController::class, 'dispatchTicketPdf'])->name('documents.dispatch.ticket.pdf');
        Route::redirect('/despachos/ticket_salida.php', '/documentos/salida/ticket', 302);
    });

    Route::middleware('role:1')->group(function (): void {
        Route::get('/rentas', [RentalController::class, 'index'])->name('rentals.index');
        Route::redirect('/rentas/rentas.php', '/rentas', 302);
        Route::get('/rentas/espacios', [RentalController::class, 'spaces'])->name('rentals.spaces');
        Route::redirect('/rentas/espacios.php', '/rentas/espacios', 302);
        Route::get('/rentas/configuracion', [RentalController::class, 'settings'])->name('rentals.settings');
        Route::post('/rentas/predios', [RentalController::class, 'storeLocation'])->name('rentals.locations.store');
        Route::post('/rentas/predios/{location}/espacios', [RentalController::class, 'storeSpaces'])->name('rentals.spaces.store');
        Route::post('/rentas/predios/{location}/tarifas', [RentalController::class, 'storeRate'])->name('rentals.rates.store');
        Route::patch('/rentas/espacios/{space}', [RentalController::class, 'updateSpace'])->name('rentals.spaces.update');
        Route::post('/rentas/estadias', [RentalController::class, 'store'])->name('rentals.store');
        Route::get('/rentas/estadias/{stay}', [RentalController::class, 'show'])->name('rentals.show');
        Route::post('/rentas/estadias/{stay}/liquidar', [RentalController::class, 'settle'])->name('rentals.settle');
    });

    Route::get('/mantenimiento/ordenes', [MaintenanceController::class, 'orders'])
        ->middleware('role:4')
        ->name('maintenance.orders');
    Route::middleware('role:4')->group(function (): void {
        Route::get('/mantenimiento/trabajos', [MaintenanceController::class, 'jobs'])->name('maintenance.jobs');
        Route::get('/mantenimiento/contenedores/{container}', [MaintenanceController::class, 'container'])->whereNumber('container')->name('maintenance.container');
        Route::get('/mantenimiento/ordenes/nueva', [MaintenanceController::class, 'create'])->name('maintenance.create');
        Route::post('/mantenimiento/ordenes', [MaintenanceController::class, 'store'])->name('maintenance.store');
        Route::get('/mantenimiento/ordenes/{job}', [MaintenanceController::class, 'show'])->whereNumber('job')->name('maintenance.show');
        Route::patch('/mantenimiento/ordenes/{job}', [MaintenanceController::class, 'update'])->whereNumber('job')->name('maintenance.update');
        Route::get('/mantenimiento/ordenes/{job}/evidencias/{evidence}', [MaintenanceController::class, 'evidence'])->whereNumber(['job', 'evidence'])->name('maintenance.evidence');
    });
    Route::redirect('/mantenimiento/ordenes.php', '/mantenimiento/ordenes', 302)
        ->middleware('role:4');

    Route::get('/contenedores', [ContainerInventoryController::class, 'index'])
        ->middleware('role:1,2,3,4,5')
        ->name('containers.index');
    Route::redirect('/bloques/bloques.php', '/contenedores', 302)
        ->middleware('role:1,2,3,4,5');
    Route::redirect('/bloques/contenedor.php', '/contenedores', 302)
        ->middleware('role:1,2,3,4,5');
    Route::post('/bloques/verificar_contenedor_mod.php', [ContainerInventoryController::class, 'legacyCheckName'])
        ->middleware('role:1,2,3');

    Route::get('/reportes', [ReportController::class, 'index'])
        ->middleware('role:1,2,3,4,5')
        ->name('reports.index');
    Route::redirect('/reportes/reportes.php', '/reportes', 302)
        ->middleware('role:1,2,3,4,5');

    Route::get('/reportes/export/{format}', [ReportController::class, 'export'])
        ->middleware('role:1,2,3,4,5')
        ->name('reports.export');
    Route::get('/reportes/generar_inventario.php', [ReportController::class, 'legacyExport'])
        ->defaults('type', 'inventario')
        ->middleware('role:1,2,3,4,5');
    Route::get('/reportes/generar_despachos.php', [ReportController::class, 'legacyExport'])
        ->defaults('type', 'despachos')
        ->middleware('role:1,2,3,4,5');
    Route::get('/reportes/generar_movimientos.php', [ReportController::class, 'legacyExport'])
        ->defaults('type', 'movimientos')
        ->middleware('role:1,2,3,4,5');
    Route::get('/reportes/generar_auditorias.php', [ReportController::class, 'legacyExport'])
        ->defaults('type', 'auditorias')
        ->middleware('role:1,2,3,4,5');
    Route::get('/reportes/generar_traslados.php', [ReportController::class, 'legacyExport'])
        ->defaults('type', 'traslados')
        ->middleware('role:1,2,3,4,5');
    Route::get('/reportes/generar_cambios.php', [ReportController::class, 'legacyExport'])
        ->defaults('type', 'cambios')
        ->middleware('role:1,2,3,4,5');
    Route::get('/reportes/generar_historial.php', [ReportController::class, 'legacyExport'])
        ->defaults('type', 'historial')
        ->middleware('role:1,2,3,4,5');

    Route::get('/contenedores/{container}', [ContainerInventoryController::class, 'show'])
        ->middleware('role:1,2,3')
        ->name('containers.show');

    Route::get('/contenedores/{container}/editar', [ContainerInventoryController::class, 'edit'])
        ->middleware('role:1,2,3')
        ->name('containers.edit');

    Route::patch('/contenedores/{container}', [ContainerInventoryController::class, 'update'])
        ->middleware('role:1,2,3')
        ->name('containers.update');

    Route::post('/contenedores/{container}/pyp/asignar', [PypAuditController::class, 'assign'])
        ->middleware('role:1,2')
        ->name('pyp-audits.assign');

    Route::get('/seguimiento-pyp', [PypAuditController::class, 'index'])
        ->middleware('role:1,2,3,4,5')
        ->name('pyp-audits.index');

    Route::middleware('role:5')->group(function (): void {
        Route::get('/contenedores/{container}/pyp', [PypAuditController::class, 'edit'])->name('pyp-audits.edit');
        Route::patch('/contenedores/{container}/pyp', [PypAuditController::class, 'update'])->name('pyp-audits.update');
    });

    Route::view('/migracion/pendiente', 'placeholder')->name('placeholder');
});
