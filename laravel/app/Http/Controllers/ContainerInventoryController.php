<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use App\Models\EstatusContenedor;
use App\Models\EstatusIpsa;
use App\Models\Modelo;
use App\Models\Patio;
use App\Models\Sucursal;
use App\Models\TipoEstatus;
use App\Models\TipoUnidad;
use App\Rules\DocumentaryText;
use App\Services\ContainerAuditAssignment;
use App\Services\ContainerUpdateService;
use App\Services\IsoCodeCatalog;
use App\Services\TransportPlate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ContainerInventoryController extends Controller
{
    private const ACTIVE = 1;

    public function index(Request $request): View
    {
        $request->validate(['search' => ['nullable', 'string', 'max:255'], 'nombre' => ['nullable', 'string', 'max:255']]);
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        return view('containers.index', [
            'sucursal' => $branch,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
        ]);
    }

    public function show(Request $request, int $container): View
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $record = $this->containerDetailQuery($branch->id)
            ->where('c.id', $container)
            ->first();

        abort_if(! $record, 404, 'Contenedor no encontrado.');

        return view('containers.show', [
            'sucursal' => $branch,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'container' => $record,
            'backParams' => $this->redirectParams($request, $record),
        ]);
    }

    public function edit(Request $request, int $container): View
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $record = $this->containerDetailQuery($branch->id)
            ->where('c.id', $container)
            ->first();

        abort_if(! $record, 404, 'Contenedor no encontrado.');

        $isoCodes = IsoCodeCatalog::records();
        if ($record->iso_code && ! isset($isoCodes[$record->iso_code])) {
            $isoCodes[$record->iso_code] = ['name' => 'Codigo guardado anteriormente'];
        }
        [$unitStatusType, $boxStatusType] = $this->statusTypeIds();

        return view('containers.edit', [
            'returnUrl' => $this->localReturnUrl($request),
            'sucursal' => $branch,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'container' => $record,
            'backParams' => $this->redirectParams($request, $record),
            'tiposUnidad' => TipoUnidad::active()->orderBy('nombre')->get(),
            'isoCodes' => $isoCodes,
            'modelos' => Modelo::active()->where('tipo_unidad_id', $record->tipo_unidad_id)->orderBy('nombre')->get(),
            'estadosUnidad' => EstatusContenedor::active()->where('tipo_estatus_id', $unitStatusType)->orderBy('nombre')->get(),
            'estadosBox' => EstatusContenedor::active()->where('tipo_estatus_id', $boxStatusType)->orderBy('nombre')->get(),
            'estatusIpsa' => EstatusIpsa::active()->orderBy('nombre')->get(),
        ] + app(ContainerAuditAssignment::class)->formData((int) $branch->id, $record));
    }

    public function update(Request $request, int $container, ContainerUpdateService $containerUpdateService): RedirectResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        [$unitStatusType, $boxStatusType] = $this->statusTypeIds();
        abort_if(! $unitStatusType || ! $boxStatusType, 500, 'No estan configurados los tipos de estatus requeridos.');

        $request->merge([
            'codigo_contenedor' => strtoupper(trim((string) $request->input('codigo_contenedor'))),
            'iso_code' => strtoupper(trim((string) $request->input('iso_code'))),
            'placa_cabezal' => strtoupper(trim((string) $request->input('placa_cabezal'))),
            'placa_chasis' => strtoupper(trim((string) $request->input('placa_chasis'))),
        ]);

        $allowedIsoCodes = array_keys(IsoCodeCatalog::records());
        $storedIsoCode = $this->containerDetailQuery($branch->id)->where('c.id', $container)->first()?->iso_code;
        if ($storedIsoCode) {
            $allowedIsoCodes[] = $storedIsoCode;
        }

        $data = $request->validate([
            'codigo_contenedor' => ['required', 'string', 'size:11', 'regex:/^[A-Z0-9]{11}$/', Rule::unique('contenedores', 'nombre')->ignore($container)],
            'tipo_unidad' => ['required', 'integer', Rule::exists('tipos_unidad', 'id')->where('estado_id', self::ACTIVE)],
            'modelo' => ['required', 'integer', Rule::exists('modelos', 'id')->where('estado_id', self::ACTIVE)],
            'iso_code' => ['required', 'string', Rule::in($allowedIsoCodes)],
            'marca' => ['required', 'string', 'max:40', new DocumentaryText('brand')],
            'estado_unidad' => ['required', 'integer', Rule::exists('estatus_contenedores', 'id')->where('estado_id', self::ACTIVE)->where('tipo_estatus_id', $unitStatusType)],
            'observaciones_estado' => [Rule::requiredIf(fn (): bool => EstatusContenedor::active()->find($request->integer('estado_unidad'))?->requiresObservations() ?? false), 'nullable', 'string', 'min:40', 'max:255'],
            'status_box' => ['required', 'integer', Rule::exists('estatus_contenedores', 'id')->where('estado_id', self::ACTIVE)->where('tipo_estatus_id', $boxStatusType)],
            'observaciones_box' => [Rule::requiredIf(fn (): bool => EstatusContenedor::active()->find($request->integer('status_box'))?->requiresObservations() ?? false), 'nullable', 'string', 'min:40', 'max:255'],
            'estatus_ipsa' => ['required', 'integer', Rule::exists('estatus_ipsa', 'id')->where('estado_id', self::ACTIVE)],
            'transportista' => ['required', 'string', 'max:100', new DocumentaryText('company')],
            'conductor' => ['required', 'string', 'max:80', new DocumentaryText('driver')],
            'placa_cabezal' => ['required', 'string', 'max:15', 'regex:'.TransportPlate::REGEX],
            'placa_chasis' => ['required', 'string', 'max:15', 'regex:'.TransportPlate::REGEX],
            'patio_id' => ['nullable', 'integer'],
            'bloque_id' => ['nullable', 'integer'],
        ], [
            'codigo_contenedor.required' => 'El codigo del contenedor es obligatorio.',
            'codigo_contenedor.size' => 'El codigo del contenedor debe tener exactamente 11 caracteres.',
            'codigo_contenedor.regex' => 'El codigo del contenedor debe tener exactamente 11 caracteres, solo mayusculas y numeros.',
            'codigo_contenedor.unique' => 'Ya existe otro contenedor con ese nombre.',
            'tipo_unidad.required' => 'Selecciona un tipo de unidad.',
            'modelo.required' => 'Selecciona un modelo.',
            'iso_code.required' => 'Selecciona el ISO Code del contenedor.',
            'iso_code.in' => 'Selecciona un ISO Code del catalogo predefinido.',
            'marca.required' => 'Ingresa la marca del contenedor.',
            'estado_unidad.required' => 'Selecciona un estado de unidad.',
            'observaciones_estado.required' => 'Describe la falla y su ubicacion en la unidad danada.',
            'observaciones_box.required' => 'Describe el dano y su ubicacion en la estructura.',
            'observaciones_estado.min' => 'Escribe al menos 40 caracteres.',
            'observaciones_box.min' => 'Escribe al menos 40 caracteres.',
            'status_box.required' => 'Selecciona un estado de estructura.',
            'estatus_ipsa.required' => 'Selecciona un estatus IPSA.',
            'naviera.required' => 'Ingresa la naviera.',
            'transportista.required' => 'Ingresa quien trajo el contenedor.',
            'conductor.required' => 'Ingresa el nombre del piloto.',
            'placa_chasis.required' => 'Ingresa la placa del chasis.',
            'placa_cabezal.required' => 'Ingresa la placa del cabezal.',
            'placa_cabezal.regex' => TransportPlate::MESSAGE,
            'placa_chasis.regex' => TransportPlate::MESSAGE,
        ]);

        $data += $request->only(['shipping_line_id', 'audit_company_id', 'audit_reference', 'assignment_reason']);

        $modelMatchesType = Modelo::active()
            ->where('id', $data['modelo'])
            ->where('tipo_unidad_id', $data['tipo_unidad'])
            ->exists();

        if (! $modelMatchesType) {
            return back()->withErrors(['modelo' => 'El modelo seleccionado no pertenece al tipo de unidad.'])->withInput();
        }

        $redirectParams = array_filter([
            'patio_id' => $request->integer('patio_id') ?: null,
            'bloque_id' => $request->integer('bloque_id') ?: null,
        ]);

        try {
            $updatedCode = $containerUpdateService->update($request->user(), $branch->id, $container, $data);
        } catch (\RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return back()->withErrors(['container' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->localReturnUrl($request) ?? route('containers.show', ['container' => $container] + $redirectParams))
            ->with('status', "Contenedor {$updatedCode} actualizado correctamente.");
    }

    public function legacyCheckName(Request $request)
    {
        $name = strtoupper(trim((string) $request->input('nombre', '')));
        $original = strtoupper(trim((string) $request->input('nombre_original', '')));

        $exists = DB::table('contenedores')
            ->where('nombre', $name)
            ->where('nombre', '!=', $original)
            ->exists();

        return response($exists ? 'exists' : 'not_exists');
    }

    private function containersQuery(int $branchId)
    {
        return DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('estatus_contenedores as eu', 'c.estatus_unidad_id', '=', 'eu.id')
            ->leftJoin('estatus_contenedores as eb', 'c.estatus_box_id', '=', 'eb.id')
            ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
            ->leftJoin('pyp_auditoria as pa', function ($join): void {
                $join->on('c.id', '=', 'pa.contenedor_id')->where('pa.estado_id', self::ACTIVE);
            })
            ->where('c.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->select([
                'c.id',
                'c.nombre',
                'c.posicion',
                'c.fecha_ingreso',
                'c.estatus_ipsa_id',
                'eu.nombre as estatus',
                'eb.nombre as estatus_box',
                'ei.nombre as estatus_ipsa',
                'pa.estatus_pyp',
                'pa.informacion_adicional',
                'pa.contenedor_id as es_asignado',
                'b.id as bloque_id',
                'b.numero as bloque_numero',
                'p.id as patio_id',
                'p.nombre as patio_nombre',
            ]);
    }

    private function containerDetailQuery(int $branchId)
    {
        $query = DB::table('contenedores as c')
            ->leftJoin('tipos_unidad as t', 'c.tipo_unidad_id', '=', 't.id')
            ->leftJoin('modelos as m', 'c.modelo_id', '=', 'm.id')
            ->leftJoin('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->leftJoin('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('estatus_contenedores as eu', 'c.estatus_unidad_id', '=', 'eu.id')
            ->leftJoin('estatus_contenedores as eb', 'c.estatus_box_id', '=', 'eb.id')
            ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
            ->where('c.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->select([
                'c.id',
                'c.nombre',
                'c.fecha_ingreso',
                'c.posicion',
                'c.tipo_unidad_id',
                'c.modelo_id',
                'c.iso_code',
                'c.marca',
                'c.estatus_unidad_id',
                'c.observaciones_unidad',
                'c.estatus_box_id',
                'c.observaciones_box',
                'c.estatus_ipsa_id',
                'c.naviera',
                'c.transportista',
                'c.conductor',
                'c.placa_cabezal',
                'c.placa_chasis',
                't.nombre as tipo_unidad_nombre',
                'm.nombre as modelo_nombre',
                'eu.nombre as estatus_unidad',
                'eb.nombre as estatus_box',
                'ei.nombre as estatus_ipsa',
                'b.id as bloque_id',
                'b.numero as bloque_numero',
                'p.id as patio_id',
                'p.nombre as patio_nombre',
            ]);
        $query->leftJoin('pyp_auditoria as pa', function ($join): void {
            $join->on('pa.contenedor_id', '=', 'c.id')->where('pa.estado_id', self::ACTIVE);
        })->addSelect('pa.estatus_pyp');

        return app(ContainerAuditAssignment::class)->joinCurrent($query);
    }

    private function statusTypeIds(): array
    {
        return [
            TipoEstatus::where('nombre', 'Unidad')->value('id'),
            TipoEstatus::where('nombre', 'Estructura')->value('id'),
        ];
    }

    private function selectedPatio(Request $request, Collection $patios): ?Patio
    {
        if ($patios->isEmpty()) {
            return null;
        }

        $patioId = (int) $request->query('patio_id', 0);

        return $patios->firstWhere('id', $patioId) ?? $patios->first();
    }

    private function selectedBlock(Request $request, Collection $blocks): ?Bloque
    {
        if ($blocks->isEmpty()) {
            return null;
        }

        $blockId = (int) $request->query('bloque_id', 0);

        return $blocks->firstWhere('id', $blockId) ?? $blocks->first();
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }

    private function redirectParams(Request $request, object $container): array
    {
        return array_filter([
            'patio_id' => $request->integer('patio_id') ?: $container->patio_id,
            'bloque_id' => $request->integer('bloque_id') ?: $container->bloque_id,
        ]);
    }
}
