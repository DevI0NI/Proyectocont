<?php

namespace Database\Seeders;

use App\Models\Bloque;
use App\Models\User;
use App\Services\AccessPermissions;
use App\Services\ContainerUpdateService;
use App\Services\DispatchService;
use App\Services\IngresoService;
use App\Services\MaintenanceCorrections;
use App\Services\MaintenanceWorkflow;
use App\Services\PypAuditService;
use App\Services\TransferService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemonstrationSeeder extends Seeder
{
    private const DIAGNOSIS = 'Abolladura localizada en dos nervaduras del panel lateral junto al marco posterior, con pintura desprendida y corrosion superficial.';

    private const REJECTION = 'Persiste corrosion sin tratar en el borde inferior derecho del parche. Preparar la superficie y completar el acabado protector.';

    /** @var array<string, int> */
    private array $statuses = [];

    /** @var array<string, string> */
    private array $images = [];

    private Carbon $baseDate;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'mysql'
            || ! preg_match('/^proportsa_demo_[a-z0-9_]+$/', DB::connection()->getDatabaseName())) {
            throw new RuntimeException('Solo se permite cargar en una base MySQL proportsa_demo_* local o de pruebas.');
        }
        if (DB::table('contenedores')->exists() || DB::table('container_ingress_cycles')->exists()) {
            throw new RuntimeException('La base de demostracion debe estar vacia de operaciones; no se eliminan ni duplican registros.');
        }
        $password = (string) env('DEMO_USER_PASSWORD', '');
        if (strlen($password) < 12 || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[0-9]/', $password) || ! preg_match('/[^a-zA-Z0-9]/', $password)) {
            throw new RuntimeException('Define DEMO_USER_PASSWORD con al menos 12 caracteres, mayuscula, numero y simbolo.');
        }
        $branches = DB::table('sucursales')->where('estado_id', 1)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (count($branches) < 3 || ! User::where('rol_id', 1)->where('estado_id', 1)->exists()) {
            throw new RuntimeException('Copia primero los usuarios, sucursales y catalogos protegidos a la base de demostracion.');
        }
        foreach (['panel-damage', 'panel-incomplete', 'panel-corrected'] as $name) {
            $path = database_path('seeders/evidence/'.$name.'.jpg');
            if (! is_file($path) || filesize($path) > 2097152) {
                throw new RuntimeException('Falta evidencia JPEG sintetica de menos de 2 MB: '.$name);
            }
            $this->images[$name] = $path;
        }
        $previousTime = Carbon::getTestNow();
        $this->baseDate = Carbon::now('UTC')->startOfDay();
        $mail = config('mail.default');
        $queue = config('queue.default');
        $root = config('filesystems.disks.local.root');
        $demoRoot = storage_path('app/demo/'.DB::connection()->getDatabaseName());
        config(['mail.default' => 'array', 'queue.default' => 'null', 'filesystems.disks.local.root' => $demoRoot]);
        Storage::forgetDisk('local');
        try {
            DB::transaction(function () use ($branches, $password): void {
                $this->loadStatuses();
                $catalogs = $this->catalogs($branches);
                $accounts = $this->accounts($branches, $catalogs['companies'], $password);
                DB::table('maintenance_counters')->updateOrInsert(['name' => 'review_request'], ['last_value' => 0]);
                $serial = 0;
                foreach ($branches as $index => $branch) {
                    $blocks = [];
                    foreach (['Patio de recepcion', 'Patio de mantenimiento', 'Patio de equipos disponibles'] as $name) {
                        $yard = DB::table('patios')->insertGetId(['nombre' => $name, 'sucursal_id' => $branch, 'estado_id' => 1]);
                        foreach ([1, 2, 3] as $number) {
                            $blocks[] = (int) DB::table('bloques')->insertGetId(['numero' => $number, 'patio_id' => $yard, 'capacidad_maxima' => 25, 'capacidad_actual' => 0, 'estado_id' => 1]);
                        }
                    }
                    $mechanic = $accounts['mechanics'][$index % 3];
                    if ((int) $mechanic->sucursal_id !== $branch) {
                        $mechanic = User::create(['username' => 'Tecnico '.DB::table('sucursales')->where('id', $branch)->value('nombre'),
                            'email' => 'demo.mechanic.branch.'.$branch.'@example.test', 'password' => Hash::make($password),
                            'rol_id' => 4, 'sucursal_id' => $branch, 'estado_id' => 1]);
                    }
                    $periods = [Carbon::create(2025, 1, 10, 8, 0, 0, 'UTC'), Carbon::create(2025, 8, 5, 8, 0, 0, 'UTC'),
                        Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'), $this->baseDate->copy()->subDays(21)->setHour(8)];
                    foreach ($periods as $period => $start) {
                        for ($scenario = 0; $scenario < 8; $scenario++) {
                            $source = $blocks[$scenario];
                            $target = $blocks[($scenario + 1) % count($blocks)];
                            $this->scenario(++$serial, $period < 3 ? 7 : $scenario, $branch, ($index + $scenario) % 3,
                                [$source, $target], $catalogs, $mechanic, $accounts['auditors'][($index + $scenario) % 3],
                                $start->copy()->addDays($scenario), $period < 3 ? 45 : 3);
                        }
                    }
                    for ($healthy = 0; $healthy < 8; $healthy++) {
                        $this->healthyContainer(++$serial, $branch, ($index + $healthy) % 3, $blocks[$healthy + 1], $catalogs,
                            $accounts['auditors'][($index + $healthy) % 3], $this->baseDate->copy()->subDays(8 - $healthy)->setHour(8));
                    }
                }
                // Imported schemas can retain legacy insert triggers in addition to service counters.
                foreach (DB::table('bloques')->get() as $block) {
                    DB::table('bloques')->where('id', $block->id)->update(['capacidad_actual' => DB::table('contenedores')
                        ->where('bloque_id', $block->id)->where('estado_id', 1)->count()]);
                }
                $this->command?->info($serial.' contenedores de demostracion creados. Los usuarios anteriores se conservaron sin cambios.');
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->deleteDirectory('maintenance');
            Storage::disk('local')->deleteDirectory('external-audits');
            Storage::disk('local')->deleteDirectory('maintenance-corrections');
            throw $exception;
        } finally {
            Carbon::setTestNow($previousTime);
            config(['mail.default' => $mail, 'queue.default' => $queue, 'filesystems.disks.local.root' => $root]);
            Storage::forgetDisk('local');
        }
    }

    private function loadStatuses(): void
    {
        foreach (['unit_ok' => ['OK', 'Unidad'], 'box_ok' => ['OK', 'Estructura'], 'box_damage' => ['DMG', 'Estructura'], 'unit_pending' => ['Pendiente PTI', 'Unidad']] as $key => [$name, $type]) {
            $this->statuses[$key] = (int) DB::table('estatus_contenedores as s')->join('tipos_estatus as t', 't.id', '=', 's.tipo_estatus_id')
                ->where('s.nombre', $name)->where('t.nombre', $type)->where('s.estado_id', 1)->value('s.id');
        }
        foreach (['available' => 'Disponible', 'oos' => 'OOS', 'pending' => 'Pendiente'] as $key => $name) {
            $this->statuses[$key] = (int) DB::table('estatus_ipsa')->where('nombre', $name)->where('estado_id', 1)->value('id');
        }
        if (in_array(0, $this->statuses, true)) {
            throw new RuntimeException('Faltan estados operativos protegidos.');
        }
    }

    /** @return array{companies: list<int>, lines: list<int>, models: list<array{type: int, model: int, iso: string}>} */
    private function catalogs(array $branches): array
    {
        $companies = $lines = $models = [];
        foreach (DB::table('external_audit_companies')->orderBy('id')->get() as $company) {
            DB::table('external_audit_companies')->where('id', $company->id)->update([
                'name' => 'Inspeccion Portuaria Central '.$company->id.' (Demo)', 'name_key' => 'demo-central-'.$company->id,
                'description' => 'Entidad ficticia de demostracion; mantiene las relaciones de las cuentas conservadas.',
                'contact_name' => null, 'email' => 'central.'.$company->id.'@example.test', 'phone' => null,
            ]);
        }
        foreach (['Caribe', 'Atlantico', 'Regional'] as $index => $region) {
            foreach (['external_audit_companies' => 'Inspeccion Tecnica ', 'shipping_lines' => 'Transporte Maritimo '] as $table => $prefix) {
                $record = ['name' => $prefix.$region.' (Demo)', 'name_key' => 'demo-'.strtolower($region),
                    'description' => 'Entidad ficticia para demostracion academica; no representa una empresa real.',
                    'email' => 'operaciones.'.strtolower($region).'@example.test', 'status' => 1, 'created_at' => now(), 'updated_at' => now()];
                $existing = $table === 'external_audit_companies'
                    ? DB::table($table)->where('name_key', 'like', 'demo-central-%')->orderBy('id')->first() : null;
                if ($existing) {
                    $id = (int) $existing->id;
                    DB::table($table)->where('id', $id)->update($record);
                } else {
                    $id = (int) DB::table($table)->insertGetId($record);
                }
                if ($table === 'external_audit_companies') {
                    $companies[] = $id;
                } else {
                    $lines[] = $id;
                }
            }
            DB::table('shipping_line_auditor')->insert(['shipping_line_id' => $lines[$index], 'auditor_id' => $companies[$index]]);
            foreach ($branches as $branch) {
                DB::table('shipping_line_branch')->insert(['shipping_line_id' => $lines[$index], 'branch_id' => $branch]);
                DB::table('shipping_line_branch_auditor')->insert(['shipping_line_id' => $lines[$index], 'branch_id' => $branch, 'auditor_id' => $companies[$index]]);
            }
        }
        foreach (['Uso general' => [['20 pies estandar', '22G1'], ['40 pies estandar', '42G1'], ['40 pies High Cube', '45G1']],
            'Refrigerado' => [['20 pies refrigerado', '22R1'], ['40 pies High Cube refrigerado', '45R1']],
            'Techo abierto' => [['20 pies techo abierto', '22U1']]] as $type => $variants) {
            $typeId = (int) DB::table('tipos_unidad')->insertGetId(['nombre' => $type, 'descripcion' => 'Clasificacion operativa de contenedor.', 'estado_id' => 1]);
            foreach ($variants as [$name, $iso]) {
                $id = (int) DB::table('modelos')->insertGetId(['nombre' => $name, 'tipo_unidad_id' => $typeId, 'estado_id' => 1]);
                if ($type === 'Uso general') {
                    $models[] = ['type' => $typeId, 'model' => $id, 'iso' => $iso];
                }
            }
        }

        return compact('companies', 'lines', 'models');
    }

    /** @return array<string, list<User>> */
    private function accounts(array $branches, array $companies, string $password): array
    {
        $accounts = [];
        $names = ['admins' => ['Laura Castillo', 'Gabriel Mendez', 'Sofia Morales'], 'mechanics' => ['Carlos Herrera', 'Luis Cabrera', 'Mario Fuentes'],
            'operators' => ['Ana Lopez', 'Diego Perez', 'Elena Ramirez'], 'auditors' => ['Isabel Torres', 'Daniel Aguilar', 'Lucia Ramos']];
        foreach (['admins' => 2, 'mechanics' => 4, 'operators' => 3, 'auditors' => 5] as $group => $role) {
            for ($index = 0; $index < 3; $index++) {
                $user = User::create(['username' => $names[$group][$index], 'email' => 'demo.'.$group.'.'.($index + 1).'@example.test',
                    'password' => Hash::make($password), 'rol_id' => $role, 'sucursal_id' => $branches[$index], 'estado_id' => 1,
                    'permissions' => AccessPermissions::defaults($role), 'external_audit_company_id' => $role === 5 ? $companies[$index] : null,
                    'external_access_level' => $role === 5 ? ($index === 0 ? 'supervisor' : 'inspector') : null]);
                if ($role === 5) {
                    $user->authorizedExternalBranches()->sync($branches);
                }
                $accounts[$group][] = $user;
            }
        }

        return $accounts;
    }

    private function scenario(int $serial, int $scenario, int $branch, int $owner, array $blocks, array $catalogs, User $mechanic, User $auditor, Carbon $clock, int $reentryDays): void
    {
        Carbon::setTestNow($clock);
        $admin = User::where('rol_id', 1)->where('estado_id', 1)->firstOrFail();
        $variant = $catalogs['models'][$serial % 3];
        $data = ['fecha_ingreso' => $clock->format('Y-m-d'), 'posicion' => $this->nextPosition($blocks[0]),
            'tipo_unidad' => $variant['type'], 'modelo' => $variant['model'], 'iso_code' => $variant['iso'], 'marca' => 'CIMC',
            'estado_unidad' => $this->statuses[$scenario === 0 ? 'unit_pending' : 'unit_ok'], 'status_box' => $this->statuses[$scenario === 0 ? 'box_ok' : 'box_damage'],
            'estatus_ipsa' => $this->statuses[$scenario === 0 ? 'pending' : 'oos'], 'observaciones_estado' => '', 'observaciones_box' => $scenario === 0 ? '' : self::DIAGNOSIS,
            'shipping_line_id' => $catalogs['lines'][$owner], 'audit_company_id' => $catalogs['companies'][$owner],
            'audit_reference' => 'Demostracion academica con evidencias sinteticas reutilizables; no operacion real.',
            'transportista' => 'Logistica Portuaria del Caribe Demo', 'conductor' => 'Roberto Salazar', 'placa_cabezal' => 'C123ABC', 'placa_chasis' => 'TC456DEF'];
        $containerId = (int) app(IngresoService::class)->register($admin, $data, $this->containerCode($serial), Bloque::with('patio')->findOrFail($blocks[0]))['container_id'];
        $this->dateLogs($containerId);
        if ($scenario < 2) {
            return;
        }
        Carbon::setTestNow($clock->copy()->addHours(1));
        $workflow = app(MaintenanceWorkflow::class);
        $jobId = $workflow->start($mechanic, $branch, ['container_id' => $containerId, 'diagnosis' => self::DIAGNOSIS], [$this->upload('panel-damage')]);
        if ($scenario === 2) {
            return;
        }
        Carbon::setTestNow($clock->copy()->addHours(3));
        $service = (int) DB::table('repair_services')->where('code', 'RPR-BASE-011')->value('id');
        $part = (int) DB::table('repair_parts')->where('code', 'REP-BASE-013')->value('id');
        $workflow->save($mechanic, $branch, $jobId, ['version' => 1, 'action' => 'submit', 'diagnosis' => self::DIAGNOSIS,
            'repair_service_id' => $service, 'materials' => [['part_id' => $part, 'quantity' => '0.500']],
            'work_performed' => 'Se enderezaron las nervaduras del panel lateral y se aplico imprimacion anticorrosiva en la zona preparada.'], ['after' => [$this->upload('panel-incomplete')]]);
        if ($scenario === 3) {
            return;
        }
        Carbon::setTestNow($clock->copy()->addHours(4));
        app(MaintenanceCorrections::class)->confirm($admin, $branch, $jobId, 2);
        app(ContainerUpdateService::class)->update($admin, $branch, $containerId, array_replace($data, ['codigo_contenedor' => $this->containerCode($serial),
            'status_box' => $this->statuses['box_ok'], 'estatus_ipsa' => $this->statuses['available'], 'observaciones_box' => '']));
        $this->dateLogs($containerId);
        if ($scenario === 4) {
            $this->audit($auditor, $branch, $containerId, $jobId, null);

            return;
        }
        Carbon::setTestNow($clock->copy()->addHours(5));
        $this->audit($auditor, $branch, $containerId, $jobId, 'Rechazado');
        $this->dateLogs($containerId);
        if ($scenario === 5) {
            return;
        }
        Carbon::setTestNow($clock->copy()->addHours(7));
        $round = DB::table('maintenance_corrections')->where('job_id', $jobId)->latest('number')->first();
        app(MaintenanceCorrections::class)->save($mechanic, $branch, $jobId, ['round_id' => $round->id, 'version' => $round->version, 'action' => 'submit',
            'repair_service_id' => $service, 'materials' => [['part_id' => $part, 'quantity' => '0.250']],
            'diagnosis' => 'Se identifica corrosion residual en el borde inferior derecho del parche de proteccion del panel lateral.',
            'work_performed' => 'Se elimino la corrosion residual del borde del parche y se completo la proteccion anticorrosiva con acabado uniforme.'],
            [$this->upload('panel-corrected')], [$this->upload('panel-incomplete')]);
        if ($scenario === 6) {
            return;
        }
        Carbon::setTestNow($clock->copy()->addHours(9));
        $this->audit($auditor, $branch, $containerId, $jobId, 'Aprobado');
        app(TransferService::class)->transfer($admin, $branch, ['contenedor_id' => $containerId, 'nuevo_bloque_id' => $blocks[1], 'nueva_posicion' => $this->nextPosition($blocks[1])]);
        $this->dateLogs($containerId);
        Carbon::setTestNow($clock->copy()->addHours(10));
        app(DispatchService::class)->dispatch($admin, $branch, ['contenedor_id' => $containerId, 'transportista' => $data['transportista'],
            'conductor' => $data['conductor'], 'placa_cabezal' => 'C123ABC', 'placa_chasis' => 'TC456DEF',
            'observaciones' => 'Equipo retirado tras verificar la correccion tecnica, el dictamen favorable y la documentacion del transporte.']);
        $this->dateLogs($containerId);
        for ($reentry = 1; $reentry <= $serial % 3; $reentry++) {
            Carbon::setTestNow($clock->copy()->addDays($reentryDays * $reentry));
            app(IngresoService::class)->register($admin, array_replace($data, ['fecha_ingreso' => now()->format('Y-m-d'), 'posicion' => $this->nextPosition($blocks[1]),
                'status_box' => $this->statuses['box_ok'], 'estatus_ipsa' => $this->statuses['available'], 'observaciones_box' => '']),
                $this->containerCode($serial), Bloque::with('patio')->findOrFail($blocks[1]));
            $this->audit($auditor, $branch, $containerId, null, 'Aprobado');
            Carbon::setTestNow($clock->copy()->addDays($reentryDays * $reentry)->addHours(8));
            app(DispatchService::class)->dispatch($admin, $branch, ['contenedor_id' => $containerId, 'transportista' => $data['transportista'],
                'conductor' => $data['conductor'], 'placa_cabezal' => 'C123ABC', 'placa_chasis' => 'TC456DEF',
                'observaciones' => 'Retiro del nuevo ciclo tras verificar una inspeccion independiente, el dictamen vigente y las condiciones de salida.']);
            $this->dateLogs($containerId);
        }
    }

    private function healthyContainer(int $serial, int $branch, int $owner, int $block, array $catalogs, User $auditor, Carbon $clock): void
    {
        Carbon::setTestNow($clock);
        $variant = $catalogs['models'][$serial % 3];
        $data = ['fecha_ingreso' => $clock->format('Y-m-d'), 'posicion' => $this->nextPosition($block),
            'tipo_unidad' => $variant['type'], 'modelo' => $variant['model'], 'iso_code' => $variant['iso'], 'marca' => 'CIMC',
            'estado_unidad' => $this->statuses['unit_ok'], 'status_box' => $this->statuses['box_ok'], 'estatus_ipsa' => $this->statuses['available'],
            'observaciones_estado' => 'Unidad recibida sin defectos operativos detectados durante la inspeccion tecnica de recepcion.',
            'observaciones_box' => 'Paneles, piso y puertas revisados en recepcion, sin deterioro que requiera una reparacion correctiva.',
            'shipping_line_id' => $catalogs['lines'][$owner], 'audit_company_id' => $catalogs['companies'][$owner],
            'audit_reference' => 'Escenario de demostracion: ingreso en buen estado sin reparacion mecanica.',
            'transportista' => 'Transportes Portuarios Regionales Demo',
            'conductor' => ['Roberto Salazar', 'Miguel Castillo', 'Jose Ramirez'][$owner], 'placa_cabezal' => 'C123ABC', 'placa_chasis' => 'TC456DEF'];
        $admin = User::where('rol_id', 1)->where('estado_id', 1)->firstOrFail();
        $containerId = (int) app(IngresoService::class)->register($admin, $data, $this->containerCode($serial), Bloque::with('patio')->findOrFail($block))['container_id'];
        if ($serial % 2 === 0) {
            Carbon::setTestNow($clock->copy()->addHours(2));
            $this->audit($auditor, $branch, $containerId, null, 'Aprobado');
        }
        $this->dateLogs($containerId);
    }

    private function nextPosition(int $block): int
    {
        $occupied = DB::table('contenedores')->where('bloque_id', $block)->where('estado_id', 1)->pluck('posicion')->all();
        for ($position = 25; $position > 0; $position--) {
            if (! in_array($position, $occupied)) {
                return $position;
            }
        }
        throw new RuntimeException('El bloque de demostracion esta lleno.');
    }

    private function audit(User $auditor, int $branch, int $containerId, ?int $jobId, ?string $result): void
    {
        $service = app(PypAuditService::class);
        $service->begin($auditor, $branch, $containerId, $this->revision($containerId));
        if ($result !== null) {
            $service->update($auditor, $branch, $containerId, ['action' => 'finalize', 'estatus_pyp' => $result,
                'informacion_adicional' => $result === 'Rechazado' ? self::REJECTION : ($jobId
                    ? 'Se verifico el tratamiento del borde observado y la continuidad del acabado protector. La correccion localizada se acepta sin observaciones pendientes.'
                    : 'Se revisaron paneles, puertas y piso del ingreso actual. No se detectaron defectos que impidan aceptar el equipo en este ciclo.'),
                'audit_version' => $this->revision($containerId), 'maintenance_version' => $jobId ? (int) DB::table('maintenance_jobs')->where('id', $jobId)->value('version') : null,
                'photos' => $jobId ? [$this->upload($result === 'Rechazado' ? 'panel-incomplete' : 'panel-corrected')] : []]);
        }
        $this->dateLogs($containerId);
    }

    private function revision(int $containerId): int
    {
        return (int) DB::table('container_assignment_events')->where('container_id', $containerId)->max('id');
    }

    private function upload(string $name): UploadedFile
    {
        return new UploadedFile($this->images[$name], $name.'-sintetica-demo.jpg', 'image/jpeg', null, true);
    }

    private function dateLogs(int $containerId): void
    {
        // Legacy inserts use the database clock instead of Laravel's frozen scenario clock.
        DB::table('logs_eventos')->where('contenedor_id', $containerId)->where('fecha_evento', '>', now()->addMinute())->update(['fecha_evento' => now()]);
    }

    private function containerCode(int $serial): string
    {
        $code = 'DMPU'.str_pad((string) $serial, 6, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($code) as $index => $character) {
            $value = ctype_digit($character) ? (int) $character : ord($character) - 55;
            if (! ctype_digit($character)) {
                $value += intdiv($value - 1, 10);
            }
            $sum += $value * (2 ** $index);
        }

        return $code.(($sum % 11) % 10);
    }
}
