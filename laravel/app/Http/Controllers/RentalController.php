<?php

namespace App\Http\Controllers;

use App\Rules\DocumentaryText;
use App\Services\AdministrationTable;
use App\Services\RentalParkingService;
use App\Services\TransportPlate;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RentalController extends Controller
{
    public function __construct(private RentalParkingService $parking) {}

    public function index(Request $request): View
    {
        $locations = DB::table('rental_locations')->orderBy('name')->get();
        $location = $locations->firstWhere('id', (int) $request->query('location', $locations->first()?->id));
        $spaces = collect();
        $rate = null;
        if ($location) {
            $spaces = DB::table('rental_spaces as s')->leftJoin('rental_stays as t', 't.active_space_id', '=', 's.id')
                ->where('s.location_id', $location->id)->where('s.status', '!=', 'retired')
                ->select('s.*', 't.id as stay_id', 't.plate', 't.driver', 't.entered_at')->get()->sortBy('code', SORT_NATURAL)->values();
            $rate = DB::table('rental_rates')->where('location_id', $location->id)->orderByDesc('id')->first();
        }

        return view('rentals.index', compact('locations', 'location', 'spaces', 'rate'));
    }

    public function records(Request $request): View|RedirectResponse
    {
        $locations = DB::table('rental_locations')->orderBy('name')->get();
        $location = $locations->firstWhere('id', (int) $request->query('location', $locations->first()?->id));
        if (! $location) {
            return redirect()->route('rentals.index');
        }
        $history = $request->query('tab') === 'history';
        $scope = $history ? 'parking-history' : 'parking-stays';
        $staysQuery = DB::table('rental_stays as t')->join('rental_spaces as s', 's.id', '=', 't.space_id')
            ->where('t.location_id', $location->id)
            ->when($history, fn ($query) => $query->whereNotNull('t.exited_at'), fn ($query) => $query->whereNull('t.exited_at'))
            ->select('t.*', 's.code as space_code')
            ->selectRaw("CASE t.billing_unit WHEN 'hour' THEN 'Por hora' ELSE 'Por dia' END as billing_label")
            ->selectRaw('CASE WHEN t.exited_at IS NULL THEN t.rate_cents ELSE t.total_cents END as amount_cents');
        $table = DB::query()->fromSub($staysQuery, 'parking_stays')->select('parking_stays.*');
        $filterOptions = [
            'space' => (clone $table)->distinct()->orderBy('space_code')->pluck('space_code', 'space_code')->all(),
            'mode' => ['hour' => 'Por hora', 'day' => 'Por dia'],
        ];

        return view('rentals.records', [
            'locations' => $locations,
            'location' => $location,
            'history' => $history,
            'stays' => AdministrationTable::paginate($table, $request, $scope, 'parking_stays'),
            'tableColumns' => AdministrationTable::columns($scope),
            'filterOptions' => $filterOptions,
        ]);
    }

    public function spaces(): RedirectResponse
    {
        return redirect()->route('rentals.index');
    }

    public function settings(): View
    {
        $locations = DB::table('rental_locations')->orderBy('name')->get();
        $spaceCounts = DB::table('rental_spaces')->where('status', '!=', 'retired')->select('location_id', 'status', DB::raw('COUNT(*) as total'))->groupBy('location_id', 'status')->get()->groupBy('location_id');
        $occupied = DB::table('rental_stays')->whereNull('exited_at')->select('location_id', DB::raw('COUNT(*) as total'))->groupBy('location_id')->pluck('total', 'location_id');
        $rates = DB::table('rental_rates')->orderByDesc('id')->get()->unique('location_id')->keyBy('location_id');

        return view('rentals.settings', compact('locations', 'spaceCounts', 'occupied', 'rates'));
    }

    public function locationSettings(Request $request, int $location): View|RedirectResponse
    {
        $record = DB::table('rental_locations')->where('id', $location)->first();
        abort_unless($record, 404);
        $spacesQuery = DB::table('rental_spaces as s')->leftJoin('rental_stays as t', 't.active_space_id', '=', 's.id')
            ->where('s.location_id', $location)->where('s.status', '!=', 'retired');
        $all = $spacesQuery->select('s.*', 't.plate')->get()->sortBy('code', SORT_NATURAL)->values();
        $page = max(1, $request->integer('page', 1));
        $spaces = (new LengthAwarePaginator($all->forPage($page, 24)->values(), $all->count(), 24, $page, ['path' => $request->url(), 'query' => $request->query()]))->fragment('espacios');
        if ($spaces->isEmpty() && $spaces->currentPage() > 1) {
            return redirect()->to($spaces->url($spaces->lastPage()));
        }
        $rate = DB::table('rental_rates')->where('location_id', $location)->orderByDesc('id')->first();
        $counts = [
            'total' => $all->count(),
            'occupied' => $all->whereNotNull('plate')->count(),
            'blocked' => $all->where('status', 'maintenance')->count(),
        ];
        $suggestedPrefix = $all->map(fn ($space) => strtok($space->code, '-'))->countBy()->sortDesc()->keys()->first() ?? 'A';
        $lastSpaces = $all->groupBy(fn ($space) => $this->spaceCodeParts($space->code)[0])
            ->map(fn ($row) => $row->sortByDesc(fn ($space) => $this->spaceCodeParts($space->code)[1])->first());

        return view('rentals.location-settings', [
            'location' => $record,
            'spaces' => $spaces,
            'counts' => $counts,
            'rate' => $rate,
            'suggestedPrefix' => $suggestedPrefix,
            'removableIds' => $lastSpaces->pluck('id')->all(),
            'lastCodes' => $lastSpaces->pluck('code')->all(),
        ]);
    }

    public function storeLocation(Request $request): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name')), 'address' => trim((string) $request->input('address')) ?: null]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100', 'unique:rental_locations,name', new DocumentaryText('company')],
            'address' => ['nullable', 'string', 'min:5', 'max:200', 'regex:/\p{L}/u', 'regex:/^[\p{L}\p{M}\p{N}\s.,#&()\x{27}\/-]+$/u'],
            'currency' => ['required', Rule::in(['GTQ', 'USD'])],
        ], [
            'name.required' => 'Ingresa el nombre del predio.',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'name.max' => 'El nombre admite maximo 100 caracteres.',
            'name.unique' => 'Ya existe un predio con ese nombre.',
            'address.min' => 'La direccion debe tener al menos 5 caracteres.',
            'address.max' => 'La direccion admite maximo 200 caracteres.',
            'address.regex' => 'La direccion solo admite letras, numeros y los signos . , # - / ( ).',
            'currency.in' => 'Selecciona una moneda valida.',
        ]);
        $id = DB::transaction(function () use ($data, $request): int {
            $id = DB::table('rental_locations')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            $this->parking->event($id, null, $request->user()->id, 'Predio creado', $data);

            return $id;
        });

        return redirect()->route('rentals.settings.location', $id)->with('status', 'Predio creado. Ahora agrega sus espacios y tarifas.');
    }

    public function storeSpaces(Request $request, int $location): RedirectResponse
    {
        abort_unless(DB::table('rental_locations')->where('id', $location)->exists(), 404);
        $request->merge(['prefix' => strtoupper(trim((string) $request->input('prefix')))]);
        $data = $request->validate([
            'prefix' => ['required', 'regex:/^[A-Z]{1,2}$/'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ], [
            'prefix.required' => 'Escribe la letra de la fila.',
            'prefix.regex' => 'La fila es una o dos letras, por ejemplo A o AB.',
            'quantity.required' => 'Escribe cuantos espacios nuevos quieres sumar.',
            'quantity.integer' => 'La cantidad debe ser un numero entero.',
            'quantity.min' => 'Agrega al menos 1 espacio.',
            'quantity.max' => 'Puedes agregar maximo 100 espacios a la vez.',
        ]);
        $active = DB::table('rental_spaces')->where('location_id', $location)->where('status', '!=', 'retired')->count();
        if ($active + (int) $data['quantity'] > 500) {
            throw ValidationException::withMessages(['quantity' => 'Un predio puede tener maximo 500 espacios. Puedes sumar '.max(0, 500 - $active).' mas.']);
        }
        DB::transaction(function () use ($data, $location, $request): void {
            DB::table('rental_locations')->where('id', $location)->lockForUpdate()->first();
            $prefix = strtoupper($data['prefix']);
            $existing = DB::table('rental_spaces')->where('location_id', $location)->pluck('status', 'code')->all();
            $added = [];
            for ($number = 1; count($added) < $data['quantity']; $number++) {
                $code = $prefix.'-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                if (($existing[$code] ?? null) === 'retired') {
                    DB::table('rental_spaces')->where('location_id', $location)->where('code', $code)->update(['status' => 'available', 'updated_at' => now()]);
                } elseif (isset($existing[$code])) {
                    continue;
                } else {
                    DB::table('rental_spaces')->insert(['location_id' => $location, 'code' => $code, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
                }
                $added[] = $code;
            }
            $this->parking->event($location, null, $request->user()->id, 'Espacios creados', ['espacios' => $added]);
        });

        return back()->with('status', 'Se agregaron '.$data['quantity'].' espacios al predio.');
    }

    public function storeRate(Request $request, int $location): RedirectResponse
    {
        abort_unless(DB::table('rental_locations')->where('id', $location)->exists(), 404);
        $money = ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/'];
        $data = $request->validate(['hourly' => $money, 'daily' => $money], [
            'hourly.required' => 'Ingresa la tarifa por hora.',
            'daily.required' => 'Ingresa la tarifa por dia.',
            'hourly.regex' => 'La tarifa por hora debe ser un monto de hasta 999,999.99 con maximo 2 decimales.',
            'daily.regex' => 'La tarifa por dia debe ser un monto de hasta 999,999.99 con maximo 2 decimales.',
        ]);
        $hourly = $this->parking->cents($data['hourly']);
        $daily = $this->parking->cents($data['daily']);
        if ($hourly < 1 || $daily < 1) {
            throw ValidationException::withMessages(['hourly' => 'Las dos tarifas deben ser mayores a cero.']);
        }
        if ($daily < $hourly) {
            throw ValidationException::withMessages(['daily' => 'La tarifa por dia no puede ser menor que la tarifa por hora.']);
        }
        DB::transaction(function () use ($location, $hourly, $daily, $request): void {
            $id = DB::table('rental_rates')->insertGetId(['location_id' => $location, 'hourly_cents' => $hourly, 'daily_cents' => $daily, 'created_by' => $request->user()->id, 'created_at' => now()]);
            $this->parking->event($location, null, $request->user()->id, 'Tarifas actualizadas', ['tarifa_id' => $id, 'hora_centavos' => $hourly, 'dia_centavos' => $daily]);
        });

        return back()->with('status', 'Tarifas actualizadas. Las estadias anteriores conservan su tarifa de entrada.');
    }

    public function updateSpace(Request $request, int $space): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['available', 'maintenance'])]]);
        DB::transaction(function () use ($space, $data, $request): void {
            $record = DB::table('rental_spaces')->where('id', $space)->lockForUpdate()->first();
            abort_unless($record && $record->status !== 'retired', 404);
            if (DB::table('rental_stays')->where('active_space_id', $space)->exists()) {
                throw ValidationException::withMessages(['space' => 'No puedes bloquear un espacio ocupado. Liquida primero la estadia.']);
            }
            DB::table('rental_spaces')->where('id', $space)->update(['status' => $data['status'], 'updated_at' => now()]);
            $this->parking->event($record->location_id, null, $request->user()->id, 'Estado de espacio actualizado', ['espacio' => $record->code, 'anterior' => $record->status, 'nuevo' => $data['status']]);
        });

        return back()->with('status', 'Estado del espacio actualizado.');
    }

    /**
     * Splits a space code such as A-21 into its row letter and number.
     *
     * @return array{0: string, 1: int}
     */
    private function spaceCodeParts(string $code): array
    {
        $parts = explode('-', $code, 2);

        return [$parts[0], (int) ($parts[1] ?? 0)];
    }

    /**
     * Removes the last space of a row. A space with past stays is only retired so its receipts keep working.
     */
    public function destroySpace(Request $request, int $space): RedirectResponse
    {
        $code = DB::transaction(function () use ($space, $request): string {
            $record = DB::table('rental_spaces')->where('id', $space)->lockForUpdate()->first();
            abort_unless($record && $record->status !== 'retired', 404);
            [$prefix, $number] = $this->spaceCodeParts($record->code);
            $last = DB::table('rental_spaces')->where('location_id', $record->location_id)->where('status', '!=', 'retired')->pluck('code')
                ->filter(fn (string $code): bool => $this->spaceCodeParts($code)[0] === $prefix)
                ->sortByDesc(fn (string $code): int => $this->spaceCodeParts($code)[1])->first();
            if ($last !== $record->code) {
                throw ValidationException::withMessages(['space' => "Solo puedes quitar el ultimo espacio de la fila {$prefix} ({$last}), para que no queden huecos. Si {$record->code} no se puede usar, bloquealo."]);
            }
            if (DB::table('rental_stays')->where('active_space_id', $space)->exists()) {
                throw ValidationException::withMessages(['space' => 'No puedes quitar un espacio ocupado. Cobra primero la salida del vehiculo.']);
            }
            if (DB::table('rental_stays')->where('space_id', $space)->exists()) {
                DB::table('rental_spaces')->where('id', $space)->update(['status' => 'retired', 'updated_at' => now()]);
            } else {
                DB::table('rental_spaces')->where('id', $space)->delete();
            }
            $this->parking->event($record->location_id, null, $request->user()->id, 'Espacio quitado', ['espacio' => $record->code]);

            return $record->code;
        });

        return back()->with('status', "Espacio {$code} quitado del predio.");
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['plate' => strtoupper(trim((string) $request->input('plate')))]);
        $data = $request->validate([
            'space_id' => ['required', 'integer', 'exists:rental_spaces,id'],
            'plate' => ['required', 'string', 'max:15', 'regex:'.TransportPlate::REGEX],
            'driver' => ['required', 'string', 'max:80', new DocumentaryText('driver')],
            'customer' => ['nullable', 'string', 'max:100', new DocumentaryText('company')],
            'billing_unit' => ['required', Rule::in(['hour', 'day'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'space_id.required' => 'Toca un espacio libre en el plano.',
            'plate.required' => 'Ingresa la placa del vehiculo.',
            'plate.regex' => TransportPlate::MESSAGE,
            'driver.required' => 'Ingresa el nombre del conductor.',
            'driver.max' => 'El nombre del conductor admite maximo 80 caracteres.',
            'customer.max' => 'El cliente o empresa admite maximo 100 caracteres.',
            'notes.max' => 'Las observaciones admiten maximo 500 caracteres.',
        ]);
        $data['plate'] = preg_replace('/[\s-]+/', '', $data['plate']);
        try {
            $id = $this->parking->enter($data, $request->user()->id);
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
            throw ValidationException::withMessages(['space_id' => 'El espacio o la placa ya tiene una estadia activa. Actualiza la vista.']);
        }

        $stay = DB::table('rental_stays as t')->join('rental_spaces as s', 's.id', '=', 't.space_id')->where('t.id', $id)->select('t.location_id', 't.plate', 's.code')->first();

        return redirect()->route('rentals.index', ['location' => $stay->location_id])->with('status', "Entrada registrada: {$stay->plate} en el espacio {$stay->code}.");
    }

    public function show(Request $request, int $stay): View
    {
        $record = DB::table('rental_stays as t')->join('rental_spaces as s', 's.id', '=', 't.space_id')
            ->join('rental_locations as l', 'l.id', '=', 't.location_id')->where('t.id', $stay)
            ->select('t.*', 's.code as space_code', 'l.name as location_name')->first();
        abort_unless($record, 404);
        $quote = $this->parking->quote($record);
        $events = DB::table('rental_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')->where('e.stay_id', $stay)->orderBy('e.id')->select('e.*', 'u.username')->get();

        $returnTo = $this->recordsReturn($request->query('return_to'));

        return view('rentals.show', [
            'stay' => $record,
            'quote' => $quote,
            'events' => $events,
            'returnTo' => $returnTo,
            'backUrl' => $returnTo ?? route('rentals.index', ['location' => $record->location_id]),
            'backLabel' => $returnTo ? 'Volver a registros' : 'Volver al plano',
        ]);
    }

    /**
     * Only a path to the parking records page is accepted, so the back button cannot leave the site.
     */
    private function recordsReturn(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('#^/rentas/registros(\?[^\s]*)?$#', $value)) {
            return null;
        }

        return mb_substr($value, 0, 500);
    }

    public function settle(Request $request, int $stay): RedirectResponse
    {
        $data = $request->validate([
            'expected_total_cents' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::in(['cash', 'transfer'])],
            'payment_reference' => ['nullable', 'required_if:payment_method,transfer', 'string', 'max:100'],
            'payment_confirmed' => ['accepted'],
        ]);
        $this->parking->settle($stay, $data, $request->user()->id);

        return redirect()->route('rentals.show', array_filter(['stay' => $stay, 'return_to' => $this->recordsReturn($request->input('return_to'))]))
            ->with('status', 'Liquidacion registrada y espacio liberado.');
    }
}
