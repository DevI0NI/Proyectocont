<?php

namespace App\Http\Controllers;

use App\Rules\DocumentaryText;
use App\Services\AdministrationTable;
use App\Services\RentalParkingService;
use App\Services\TransportPlate;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                ->where('s.location_id', $location->id)->orderBy('s.id')
                ->select('s.*', 't.id as stay_id', 't.plate', 't.driver', 't.entered_at')->get();
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
        $spaceCounts = DB::table('rental_spaces')->select('location_id', 'status', DB::raw('COUNT(*) as total'))->groupBy('location_id', 'status')->get()->groupBy('location_id');
        $occupied = DB::table('rental_stays')->whereNull('exited_at')->select('location_id', DB::raw('COUNT(*) as total'))->groupBy('location_id')->pluck('total', 'location_id');
        $rates = DB::table('rental_rates')->orderByDesc('id')->get()->unique('location_id')->keyBy('location_id');

        return view('rentals.settings', compact('locations', 'spaceCounts', 'occupied', 'rates'));
    }

    public function locationSettings(int $location): View
    {
        $record = DB::table('rental_locations')->where('id', $location)->first();
        abort_unless($record, 404);
        $spaces = DB::table('rental_spaces as s')->leftJoin('rental_stays as t', 't.active_space_id', '=', 's.id')
            ->where('s.location_id', $location)->orderBy('s.id')->select('s.*', 't.plate')->get();
        $rate = DB::table('rental_rates')->where('location_id', $location)->orderByDesc('id')->first();

        return view('rentals.location-settings', ['location' => $record, 'spaces' => $spaces, 'rate' => $rate]);
    }

    public function storeLocation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:rental_locations,name'],
            'address' => ['nullable', 'string', 'max:200'],
            'currency' => ['required', Rule::in(['GTQ', 'USD'])],
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
        $data = $request->validate(['prefix' => ['required', 'regex:/^[A-Za-z0-9]{1,8}$/'], 'quantity' => ['required', 'integer', 'min:1', 'max:100']]);
        DB::transaction(function () use ($data, $location, $request): void {
            DB::table('rental_locations')->where('id', $location)->lockForUpdate()->first();
            $prefix = strtoupper($data['prefix']);
            $existing = DB::table('rental_spaces')->where('location_id', $location)->pluck('code')->all();
            $added = [];
            for ($number = 1; count($added) < $data['quantity']; $number++) {
                $code = $prefix.'-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                if (in_array($code, $existing, true)) {
                    continue;
                }
                DB::table('rental_spaces')->insert(['location_id' => $location, 'code' => $code, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
                $added[] = $code;
            }
            $this->parking->event($location, null, $request->user()->id, 'Espacios creados', ['espacios' => $added]);
        });

        return back()->with('status', 'Espacios agregados al predio.');
    }

    public function storeRate(Request $request, int $location): RedirectResponse
    {
        abort_unless(DB::table('rental_locations')->where('id', $location)->exists(), 404);
        $money = ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/'];
        $data = $request->validate(['hourly' => $money, 'daily' => $money]);
        $hourly = $this->parking->cents($data['hourly']);
        $daily = $this->parking->cents($data['daily']);
        if ($hourly < 1 || $daily < 1) {
            throw ValidationException::withMessages(['hourly' => 'Las dos tarifas deben ser mayores a cero.']);
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
            abort_unless($record, 404);
            if (DB::table('rental_stays')->where('active_space_id', $space)->exists()) {
                throw ValidationException::withMessages(['space' => 'No puedes bloquear un espacio ocupado. Liquida primero la estadia.']);
            }
            DB::table('rental_spaces')->where('id', $space)->update(['status' => $data['status'], 'updated_at' => now()]);
            $this->parking->event($record->location_id, null, $request->user()->id, 'Estado de espacio actualizado', ['espacio' => $record->code, 'anterior' => $record->status, 'nuevo' => $data['status']]);
        });

        return back()->with('status', 'Estado del espacio actualizado.');
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
