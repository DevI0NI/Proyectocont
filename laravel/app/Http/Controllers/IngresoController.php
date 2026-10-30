<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use App\Models\Contenedor;
use App\Models\EstatusContenedor;
use App\Models\EstatusIpsa;
use App\Models\Modelo;
use App\Models\Patio;
use App\Models\Sucursal;
use App\Models\TipoEstatus;
use App\Models\TipoUnidad;
use App\Rules\DocumentaryText;
use App\Services\ContainerAuditAssignment;
use App\Services\IngresoService;
use App\Services\IsoCodeCatalog;
use App\Services\TransportPlate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class IngresoController extends Controller
{
    private const ACTIVE = 1;

    public function create(Request $request): View
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $unitType = TipoEstatus::where('nombre', 'Unidad')->value('id');
        $boxType = TipoEstatus::where('nombre', 'Estructura')->value('id');

        return view('ingresos.create', [
            'sucursal' => $branch,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'patios' => Patio::active()->where('sucursal_id', $branch->id)->orderBy('nombre')->get(),
            'tiposUnidad' => TipoUnidad::active()->orderBy('nombre')->get(),
            'isoCodes' => IsoCodeCatalog::records(),
            'estadosUnidad' => EstatusContenedor::active()->where('tipo_estatus_id', $unitType)->orderBy('nombre')->get(),
            'estadosBox' => EstatusContenedor::active()->where('tipo_estatus_id', $boxType)->orderBy('nombre')->get(),
            'estatusIpsa' => EstatusIpsa::active()->orderBy('nombre')->get(),
        ] + app(ContainerAuditAssignment::class)->formData((int) $branch->id));
    }

    public function store(Request $request, IngresoService $ingresoService): RedirectResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404);

        $this->normalizeDocumentaryInput($request);

        $data = $request->validate([
            'fecha_ingreso' => ['required', 'date'],
            'codigo_contenedor' => ['required', 'string', 'size:11', 'regex:/^[A-Z0-9]{11}$/'],
            'tipo_unidad' => ['required', 'integer', Rule::exists('tipos_unidad', 'id')->where('estado_id', self::ACTIVE)],
            'modelo' => ['required', 'integer', Rule::exists('modelos', 'id')->where('estado_id', self::ACTIVE)],
            'iso_code' => ['required', 'string', Rule::in(array_keys(IsoCodeCatalog::records()))],
            'marca' => ['required', 'string', 'max:40', new DocumentaryText('brand')],
            'estado_unidad' => ['required', 'integer', Rule::exists('estatus_contenedores', 'id')->where('estado_id', self::ACTIVE)],
            'observaciones_estado' => [Rule::requiredIf(fn (): bool => EstatusContenedor::active()->find($request->integer('estado_unidad'))?->requiresObservations() ?? false), 'nullable', 'string', 'min:40', 'max:255'],
            'status_box' => ['required', 'integer', Rule::exists('estatus_contenedores', 'id')->where('estado_id', self::ACTIVE)],
            'observaciones_box' => [Rule::requiredIf(fn (): bool => EstatusContenedor::active()->find($request->integer('status_box'))?->requiresObservations() ?? false), 'nullable', 'string', 'min:40', 'max:255'],
            'estatus_ipsa' => ['required', 'integer', Rule::exists('estatus_ipsa', 'id')->where('estado_id', self::ACTIVE)],
            'transportista' => ['required', 'string', 'max:100', new DocumentaryText('company')],
            'conductor' => ['required', 'string', 'max:80', new DocumentaryText('driver')],
            'placa_cabezal' => ['required', 'string', 'max:15', 'regex:'.TransportPlate::REGEX],
            'placa_chasis' => ['required', 'string', 'max:15', 'regex:'.TransportPlate::REGEX],
            'bloque' => ['required', 'integer', Rule::exists('bloques', 'id')->where('estado_id', self::ACTIVE)],
            'posicion' => ['required', 'integer', 'min:1'],
        ], [
            'fecha_ingreso.required' => 'La fecha de ingreso es obligatoria.',
            'fecha_ingreso.date' => 'La fecha de ingreso no es valida.',
            'codigo_contenedor.required' => 'El numero de equipo es obligatorio.',
            'codigo_contenedor.size' => 'El numero de equipo debe tener exactamente 11 caracteres.',
            'codigo_contenedor.regex' => 'El numero de equipo debe tener exactamente 11 caracteres, solo mayusculas y numeros.',
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
            'bloque.required' => 'Selecciona un bloque.',
            'posicion.required' => 'No se pudo calcular la posicion disponible.',
            'posicion.min' => 'La posicion debe ser mayor a cero.',
        ]);

        $containerCode = strtoupper(trim($data['codigo_contenedor']));
        $data += $request->only(['shipping_line_id', 'audit_company_id', 'audit_reference', 'assignment_reason']);
        $block = Bloque::active()->with('patio')->findOrFail($data['bloque']);

        abort_if(! $block->patio || (int) $block->patio->sucursal_id !== (int) $branch->id, 403);

        try {
            $result = $ingresoService->register($request->user(), $data, $containerCode, $block);
        } catch (\RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return back()->withErrors(['ingreso' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('documents.ingress.ticket', [
                'contenedor_id' => $result['container_id'],
                'patio_id' => $block->patio_id,
                'bloque_id' => $block->id,
            ])
            ->with('status', $result['was_reentry'] ? 'Contenedor reingresado correctamente. Ya puedes imprimir el ticket.' : 'Contenedor ingresado correctamente. Ya puedes imprimir el ticket.');
    }

    public function modelsByType(int $type): JsonResponse
    {
        return response()->json(
            Modelo::active()->where('tipo_unidad_id', $type)->orderBy('nombre')->get(['id', 'nombre'])
        );
    }

    public function blocksByPatio(Request $request, int $patio): JsonResponse
    {
        $branch = $this->activeBranch($request);
        $patioModel = Patio::active()->where('sucursal_id', $branch?->id)->findOrFail($patio);

        return response()->json(
            Bloque::active()
                ->where('patio_id', $patioModel->id)
                ->orderBy('numero')
                ->withCount(['contenedoresActivos as ocupacion_activa'])
                ->get(['id', 'numero', 'capacidad_maxima'])
                ->map(fn (Bloque $block) => [
                    'id' => $block->id,
                    'numero' => $block->numero,
                    'capacidad_maxima' => $block->capacidad_maxima,
                    'capacidad_actual' => $block->ocupacion_activa,
                ])
        );
    }

    public function nextPosition(Request $request, int $block): JsonResponse
    {
        $branch = $this->activeBranch($request);
        $blockModel = Bloque::active()->with('patio')->findOrFail($block);
        abort_if(! $blockModel->patio || (int) $blockModel->patio->sucursal_id !== (int) $branch?->id, 403);

        $occupied = Contenedor::active()->where('bloque_id', $blockModel->id)->pluck('posicion')->map(fn ($pos) => (int) $pos)->all();
        $position = (int) $blockModel->capacidad_maxima;

        while ($position > 0 && in_array($position, $occupied, true)) {
            $position--;
        }

        if ($position <= 0) {
            return response()->json(['error' => 'El bloque esta completamente lleno.'], 422);
        }

        return response()->json(['posicion' => $position]);
    }

    public function blockPreview(Request $request, int $block): JsonResponse
    {
        $branch = $this->activeBranch($request);
        $blockModel = Bloque::active()->with('patio')->findOrFail($block);
        abort_if(! $blockModel->patio || (int) $blockModel->patio->sucursal_id !== (int) $branch?->id, 403);

        $containers = Contenedor::active()
            ->where('bloque_id', $blockModel->id)
            ->orderByDesc('posicion')
            ->get(['id', 'nombre', 'posicion'])
            ->map(fn (Contenedor $container): array => [
                'id' => $container->id,
                'nombre' => $container->nombre,
                'posicion' => (int) $container->posicion,
            ])
            ->values();

        return response()->json([
            'block' => [
                'id' => $blockModel->id,
                'numero' => $blockModel->numero,
                'capacidad_maxima' => (int) $blockModel->capacidad_maxima,
                'capacidad_actual' => $containers->count(),
            ],
            'containers' => $containers,
        ]);
    }

    public function checkContainer(string $code): JsonResponse
    {
        $container = Contenedor::where('nombre', strtoupper($code))->first();

        return response()->json([
            'status' => $container && (int) $container->estado_id === self::ACTIVE ? 'exists' : 'available',
        ]);
    }

    public function legacyModels(Request $request): JsonResponse
    {
        return $this->modelsByType($request->integer('tipo_unidad_id'));
    }

    public function legacyBlocks(Request $request): JsonResponse
    {
        return $this->blocksByPatio($request, $request->integer('patio_id'));
    }

    public function legacyPosition(Request $request): JsonResponse
    {
        return $this->nextPosition($request, $request->integer('bloque_id'));
    }

    public function legacyCheckContainer(Request $request)
    {
        $container = Contenedor::where('nombre', strtoupper(trim((string) $request->input('nombre'))))->first();

        return response($container && (int) $container->estado_id === self::ACTIVE ? 'exists' : 'available');
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }

    private function normalizeDocumentaryInput(Request $request): void
    {
        $request->merge([
            'codigo_contenedor' => strtoupper(trim((string) $request->input('codigo_contenedor'))),
            'iso_code' => strtoupper(trim((string) $request->input('iso_code'))),
            'placa_cabezal' => strtoupper(trim((string) $request->input('placa_cabezal'))),
            'placa_chasis' => strtoupper(trim((string) $request->input('placa_chasis'))),
        ]);
    }
}
