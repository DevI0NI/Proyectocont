<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\AdministrationTable;
use App\Services\IsoCodeCatalog;
use App\Services\OperationalCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperationalCatalogController extends Controller
{
    public function __construct(private OperationalCatalog $catalogs) {}

    public function overview(Request $request): RedirectResponse
    {
        foreach (['navieras', 'auditoras', 'repuestos', 'reparaciones'] as $catalog) {
            if ($request->user()->hasPermission($catalog.'.view')) {
                return redirect()->route('catalogs.index', $catalog);
            }
        }
        abort(403);
    }

    public function index(Request $request, string $catalog): View|RedirectResponse
    {
        if ($catalog === 'iso') {
            return redirect()->route('ingresos.create');
        }
        $definition = OperationalCatalog::definition($catalog);
        $modernCatalog = in_array($catalog, ['navieras', 'auditoras', 'iso', 'repuestos', 'reparaciones'], true);
        $status = $request->query('status') === 'inactive' ? 2 : 1;
        $recordState = in_array($request->query('estado'), ['activos', 'inactivos', 'todos'], true)
            ? $request->query('estado') : ($status === 2 ? 'inactivos' : 'activos');
        $recordStateLabel = 'Estado de '.mb_strtolower($definition['title']);
        $query = DB::table($definition['table']);
        if (! $modernCatalog || $recordState !== 'todos') {
            $query->where('status', $modernCatalog ? ($recordState === 'inactivos' ? 2 : 1) : $status);
        }
        $records = AdministrationTable::paginate($query, $request, $catalog);
        $tableColumns = AdministrationTable::columns($catalog);
        $branches = collect();
        $auditors = collect();
        if ($catalog === 'navieras') {
            $ids = $records->pluck('id');
            $branches = DB::table('shipping_line_branch as link')->join('sucursales as branch', 'branch.id', '=', 'link.branch_id')
                ->whereIn('link.shipping_line_id', $ids)->select('link.shipping_line_id', 'branch.nombre as name', 'branch.estado_id as status')->get()->groupBy('shipping_line_id');
            $auditors = DB::table('shipping_line_auditor as link')->join('external_audit_companies as auditor', 'auditor.id', '=', 'link.auditor_id')
                ->whereIn('link.shipping_line_id', $ids)->select('link.shipping_line_id', 'auditor.name', 'auditor.status')->get()
                ->map(function ($auditor) {
                    $auditor->branch_name = null;

                    return $auditor;
                });
            $scopedAuditors = DB::table('shipping_line_branch_auditor as link')
                ->join('external_audit_companies as auditor', 'auditor.id', '=', 'link.auditor_id')
                ->join('sucursales as branch', 'branch.id', '=', 'link.branch_id')
                ->whereIn('link.shipping_line_id', $ids)->select('link.shipping_line_id', 'auditor.name', 'auditor.status', 'branch.nombre as branch_name')->get();
            $auditors = $auditors->concat($scopedAuditors)->groupBy('shipping_line_id');
        }

        $state = $modernCatalog ? compact('recordState', 'recordStateLabel') : [];

        return view('parameters.catalogs.index', $this->shell($request) + $state + compact('catalog', 'definition', 'records', 'status', 'branches', 'auditors', 'tableColumns'));
    }

    public function create(Request $request, string $catalog): View|RedirectResponse
    {
        if ($catalog === 'iso') {
            return redirect()->route('ingresos.create');
        }

        return $this->form($request, $catalog);
    }

    public function edit(Request $request, string $catalog, int $record): View|RedirectResponse
    {
        if ($catalog === 'iso') {
            return redirect()->route('ingresos.create');
        }

        return $this->form($request, $catalog, $record);
    }

    public function store(Request $request, string $catalog): RedirectResponse|JsonResponse
    {
        abort_if($catalog === 'iso', 403, 'El catalogo ISO es predefinido y no admite cambios manuales.');
        $this->persist($request, $catalog);

        if (in_array($catalog, ['navieras', 'auditoras', 'iso', 'repuestos', 'reparaciones'], true) && $request->expectsJson()) {
            $message = match ($catalog) {
                'navieras' => 'Naviera creada correctamente.',
                'auditoras' => 'Auditora creada correctamente.',
                'iso' => 'Codigo ISO creado correctamente.',
                'repuestos' => 'Repuesto creado correctamente.',
                'reparaciones' => 'Reparacion creada correctamente.',
            };
            $request->session()->flash('status', $message);

            return response()->json(['message' => $message]);
        }

        return redirect()->route('catalogs.index', $catalog)->with('status', 'Registro creado correctamente.');
    }

    public function update(Request $request, string $catalog, int $record): RedirectResponse|JsonResponse
    {
        abort_if($catalog === 'iso', 403, 'El catalogo ISO es predefinido y no admite cambios manuales.');
        $this->persist($request, $catalog, $record);

        if (in_array($catalog, ['navieras', 'auditoras', 'iso', 'repuestos', 'reparaciones'], true) && $request->expectsJson()) {
            $message = match ($catalog) {
                'navieras' => 'Naviera actualizada correctamente.',
                'auditoras' => 'Auditora actualizada correctamente.',
                'iso' => 'Codigo ISO actualizado correctamente.',
                'repuestos' => 'Repuesto actualizado correctamente.',
                'reparaciones' => 'Reparacion actualizada correctamente.',
            };
            $request->session()->flash('status', $message);

            return response()->json(['message' => $message]);
        }

        return redirect()->route('catalogs.index', $catalog)->with('status', 'Registro actualizado correctamente.');
    }

    public function status(Request $request, string $catalog, int $record): RedirectResponse|JsonResponse
    {
        abort_if($catalog === 'iso', 403, 'El catalogo ISO es predefinido y no admite cambios manuales.');
        $data = $request->validate(['status' => ['required', 'integer', Rule::in([1, 2])]]);
        $this->catalogs->setStatus($catalog, $record, (int) $data['status'], $request->user()->id);

        if ($catalog === 'repuestos' && $request->expectsJson()) {
            return response()->json(['message' => $data['status'] == 1 ? 'Repuesto reactivado.' : 'Repuesto desactivado. Su historial se conserva.']);
        }
        if ($catalog === 'reparaciones' && $request->expectsJson()) {
            return response()->json(['message' => $data['status'] == 1 ? 'Reparacion reactivada.' : 'Reparacion desactivada. Su historial se conserva.']);
        }

        if ($catalog === 'iso' && $request->expectsJson()) {
            return response()->json(['message' => $data['status'] == 1 ? 'Codigo ISO reactivado.' : 'Codigo ISO desactivado. Su historial se conserva.']);
        }
        if (in_array($catalog, ['navieras', 'auditoras'], true) && $request->expectsJson()) {
            $companyName = $catalog === 'navieras' ? 'Naviera' : 'Auditora';

            return response()->json(['message' => $companyName.($data['status'] == 1 ? ' reactivada.' : ' desactivada. Su historial se conserva.')]);
        }

        return back()->with('status', $data['status'] == 1 ? 'Registro reactivado.' : 'Registro desactivado. Su historial se conserva.');
    }

    private function form(Request $request, string $catalog, ?int $id = null): View
    {
        $definition = OperationalCatalog::definition($catalog);
        $record = $id ? $this->catalogs->snapshot($catalog, $id) : [];
        abort_if($catalog === 'repuestos' && $id && (int) $record['status'] !== 1, 403, 'Reactiva el repuesto antes de editarlo.');
        abort_if($catalog === 'reparaciones' && $id && (int) $record['status'] !== 1, 403, 'Reactiva la reparacion antes de editarla.');
        $branches = Sucursal::query()->where(function ($query) use ($record): void {
            $query->where('estado_id', 1)->orWhereIn('id', $record['branches'] ?? []);
        })->orderBy('nombre')->get();
        $linkedAuditors = array_merge($record['auditors'] ?? [], ...array_values($record['auditor_assignments'] ?? []));
        $auditors = DB::table('external_audit_companies')->where(function ($query) use ($linkedAuditors): void {
            $query->where('status', 1)->orWhereIn('id', $linkedAuditors);
        })->orderBy('name')->get();

        $isoPresets = $catalog === 'iso' ? IsoCodeCatalog::records() : [];
        if ($catalog === 'iso' && $id && ! isset($isoPresets[$record['code']])) {
            $isoPresets[$record['code']] = $record;
        }

        return view('parameters.catalogs.form', $this->shell($request) + compact('catalog', 'definition', 'record', 'branches', 'auditors', 'isoPresets'));
    }

    private function persist(Request $request, string $catalog, ?int $id = null): void
    {
        $definition = OperationalCatalog::definition($catalog);
        $existing = [];
        if ($id) {
            $existing = $this->catalogs->snapshot($catalog, $id);
        }
        $company = in_array($catalog, ['navieras', 'auditoras'], true);
        $splitPhone = $company && $request->has('phone_national');
        if ($splitPhone && $request->input('phone_national') === null) {
            $request->merge(['phone' => null]);
        }
        if ($splitPhone && is_string($request->input('phone_national')) && is_string($request->input('phone_country'))) {
            $national = trim($request->input('phone_national'));
            $request->merge(['phone' => $national === '' ? null : '+'.$request->input('phone_country').$national]);
        }
        if ($catalog === 'navieras') {
            $request->merge(['branches' => $request->input('branches', []), 'auditors' => $request->input('auditors', []), 'auditor_assignments' => $request->input('auditor_assignments', [])]);
        }
        if (is_string($request->input('name'))) {
            $name = preg_replace('/\s+/u', ' ', trim($request->input('name')));
            $request->merge(['name' => $name, 'name_key' => mb_strtolower($name)]);
        }
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
        $isoPresets = $catalog === 'iso' ? IsoCodeCatalog::records() : [];
        if ($catalog === 'iso') {
            $preset = is_string($request->input('code')) ? ($isoPresets[$request->input('code')] ?? null) : null;
            if (! $preset && $id) {
                $existing = $this->catalogs->snapshot($catalog, $id);
                if ($request->input('code') === $existing['code']) {
                    $preset = array_intersect_key($existing, $isoPresets['22G1']);
                    $isoPresets[$existing['code']] = $preset;
                }
            }
            if ($preset) {
                $request->merge($preset);
            }
        }
        if ($company && is_string($request->input('phone'))) {
            $phone = preg_replace('/[\s()\-]+/u', '', trim($request->input('phone')));
            if (str_starts_with($phone, '00')) {
                $phone = '+'.substr($phone, 2);
            }
            $request->merge(['phone' => $phone === '' ? null : $phone]);
        }
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
        if ($company) {
            $rules += [
                'name_key' => ['required', 'string', 'max:120', Rule::unique($definition['table'], 'name_key')->ignore($id)],
                'contact_name' => ['nullable', 'string', 'max:120'],
                'email' => ['nullable', 'email', 'max:150'],
                'phone' => ['nullable', 'string', 'max:30', 'regex:/\A(?:\+50[23])?[0-9]{8}\z/'],
            ];
        } elseif (! in_array($catalog, ['repuestos', 'reparaciones'], true)) {
            $rules['code'] = ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique($definition['table'], 'code')->ignore($id)];
        }
        if ($catalog === 'navieras') {
            $rules += [
                'branches' => ['required', 'array', 'min:1'],
                'branches.*' => ['required', 'integer', 'distinct', Rule::exists('sucursales', 'id')],
                'auditors' => ['nullable', 'array'],
                'auditors.*' => ['required', 'integer', 'distinct', Rule::exists('external_audit_companies', 'id')],
                'auditor_assignments' => ['present', 'array'],
                'auditor_assignments.*' => ['array'],
                'auditor_assignments.*.*' => ['required', 'integer', Rule::exists('external_audit_companies', 'id')],
            ];
        }
        if ($splitPhone) {
            $rules['phone_country'] = ['required', Rule::in(['502', '503'])];
            $rules['phone_national'] = ['nullable', 'string', 'regex:/\A[0-9]{8}\z/'];
        }
        if ($catalog === 'iso') {
            $rules['code'] = ['required', 'string', 'size:4', 'regex:/^[A-Z0-9]{4}$/', Rule::in(array_keys($isoPresets)), Rule::unique($definition['table'], 'code')->ignore($id)];
            $rules += [
                'length_mm' => ['required', 'integer', 'min:1', 'max:50000'],
                'height_mm' => ['required', 'integer', 'min:1', 'max:10000'],
                'width_mm' => ['required', 'integer', 'min:1', 'max:10000'],
                'type_name' => ['required', 'string', 'max:80'],
                'source_url' => ['nullable', 'string', 'max:500'],
            ];
        }
        if (in_array($catalog, ['repuestos', 'reparaciones'], true)) {
            $rules += [
                'reference_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
                'currency' => ['required', Rule::in(['GTQ', 'USD'])],
            ];
        }
        if ($catalog === 'repuestos') {
            $rules['unit'] = ['required', 'string', 'max:30'];
            $units = OperationalCatalog::PART_UNITS;
            if (isset($existing['unit'])) {
                $units[] = $existing['unit'];
            }
            $rules['unit'][] = Rule::in($units);
        }
        $data = $request->validate($rules, [
            'name.required' => 'Escribe el nombre del registro.',
            'name_key.required' => 'Escribe el nombre del registro.',
            'code.required' => 'Escribe el codigo.',
            'code.size' => 'El codigo ISO debe tener exactamente 4 caracteres.',
            'code.in' => 'Selecciona un codigo ISO del catalogo predefinido.',
            'code.regex' => 'El codigo solo admite letras y numeros en el formato indicado.',
            'type_name.required' => 'Escribe el tipo de contenedor.',
            'source_url.required' => 'Ingresa la referencia tecnica.',
            'source_url.url' => 'La referencia tecnica debe ser una URL valida con http o https.',
            'description.max' => 'La descripcion admite como maximo 255 caracteres.',
            'phone.regex' => 'El telefono debe tener exactamente 8 digitos, con prefijo +502 o +503 opcional.',
            'phone_country.in' => 'Selecciona Guatemala (+502) o El Salvador (+503).',
            'phone_national.regex' => 'El telefono debe tener exactamente 8 digitos.',
            'branches.required' => 'Selecciona al menos una sucursal para la naviera.',
            'name_key.unique' => 'Ese nombre ya existe, incluso si el registro esta inactivo.',
            'code.unique' => 'Ese codigo ya existe, incluso si el registro esta inactivo.',
            'reference_cost.decimal' => 'El costo admite hasta dos decimales.',
            'reference_cost.min' => $catalog === 'reparaciones' ? 'La tarifa no puede ser negativa.' : 'El costo no puede ser negativo.',
            'unit.in' => $catalog === 'reparaciones' ? 'Selecciona una unidad de cobro del catalogo.' : 'Selecciona una unidad de medida del catalogo.',
        ]);
        if ($catalog === 'navieras') {
            $data['auditors'] ??= [];
        }
        unset($data['phone_country'], $data['phone_national']);
        try {
            $this->catalogs->save($catalog, $data, $request->user()->id, $id);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique') || str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                throw ValidationException::withMessages([$company ? 'name' : 'code' => 'Ese registro ya existe. Actualiza la lista antes de continuar.']);
            }
            throw $exception;
        }
    }

    private function shell(Request $request): array
    {
        return [
            'sucursal' => Sucursal::find((int) $request->session()->get('sucursal_activa')),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
        ];
    }
}
