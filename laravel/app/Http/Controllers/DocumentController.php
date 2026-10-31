<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\ExternalAuditAccess;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DocumentController extends Controller
{
    public function ingressEir(Request $request): View
    {
        return $this->documentView($request, 'documents.eir', 'ingreso');
    }

    public function ingressTicket(Request $request): View
    {
        return $this->documentView($request, 'documents.ticket', 'ingreso');
    }

    public function ingressTicketPdf(Request $request): Response
    {
        return $this->ticketPdf($request, 'ingreso');
    }

    public function dispatchEir(Request $request): View
    {
        $branch = $this->activeBranch($request);
        abort_unless($branch, 404);
        $request->validate(['event_id' => ['nullable', 'integer', 'min:1'], 'contenedor_id' => ['nullable', 'integer', 'min:1']]);

        if ($request->filled('event_id') || $request->filled('contenedor_id')) {
            return $this->documentView($request, 'documents.ticket', 'salida');
        }

        return view('documents.dispatch-eir', [
            'sucursal' => $branch, 'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'data' => null,
            'documents' => $this->dispatchDocuments($branch->id)->orderByDesc('e.id')->paginate(20),
        ]);
    }

    public function dispatchEirPdf(Request $request): Response
    {
        return $this->ticketPdf($request, 'salida');
    }

    public function dispatchTicket(Request $request): View
    {
        return $this->documentView($request, 'documents.ticket', 'salida');
    }

    public function dispatchTicketPdf(Request $request): Response
    {
        return $this->ticketPdf($request, 'salida');
    }

    private function ticketPdf(Request $request, string $operation): Response
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $data = $this->documentData($request, $branch->id, $operation);
        if (isset($data['sucursal'])) {
            $branch->nombre = $data['sucursal'];
        }
        $logoPath = public_path('imagenes/IPSA.png');
        $logoData = file_exists($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $html = view('documents.ticket-pdf', [
            'sucursal' => $branch,
            'operation' => $operation,
            'data' => $data,
            'logoData' => $logoData,
        ])->render();

        $dompdf = new Dompdf;
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $filename = strtolower($data['numero']).'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function documentView(Request $request, string $view, string $operation): View
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $data = $this->documentData($request, $branch->id, $operation);
        if (isset($data['sucursal'])) {
            $branch->nombre = $data['sucursal'];
        }

        return view($view, [
            'sucursal' => $branch,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'operation' => $operation,
            'data' => $data,
        ]);
    }

    private function documentData(Request $request, int $branchId, string $operation): array
    {
        if ($operation === 'salida') {
            return $this->dispatchDocumentData($request, $branchId);
        }
        $request->validate(['cycle_id' => ['nullable', 'integer', 'min:1']]);
        if ($request->filled('cycle_id')) {
            $event = DB::table('container_assignment_events as e')
                ->join('container_ingress_cycles as cycle', 'cycle.id', '=', 'e.ingress_cycle_id')
                ->whereColumn('cycle.container_id', 'e.container_id')->where('cycle.branch_id', $branchId)
                ->where('cycle.id', $request->integer('cycle_id'))->whereIn('e.event_type', ['ingress', 'reentry'])
                ->orderBy('e.id')->first(['e.after_data', 'e.container_id']);
            $document = json_decode($event?->after_data ?? '{}', true)['document'] ?? null;
            abort_unless(is_array($document) && isset($document['numero'], $document['equipo']), 404, 'Este ingreso antiguo no conserva una constancia historica.');

            return $document + ['container_id' => $event->container_id];
        }
        $container = null;
        if ($request->integer('contenedor_id')) {
            $container = DB::table('contenedores as c')
                ->leftJoin('tipos_unidad as t', 'c.tipo_unidad_id', '=', 't.id')
                ->leftJoin('modelos as m', 'c.modelo_id', '=', 'm.id')
                ->leftJoin('estatus_contenedores as eu', 'c.estatus_unidad_id', '=', 'eu.id')
                ->leftJoin('estatus_contenedores as eb', 'c.estatus_box_id', '=', 'eb.id')
                ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
                ->leftJoin('bloques as b', 'c.bloque_id', '=', 'b.id')
                ->leftJoin('patios as p', 'b.patio_id', '=', 'p.id')
                ->where('c.id', $request->integer('contenedor_id'))
                ->where('p.sucursal_id', $branchId)
                ->select([
                    'c.nombre',
                    'c.id',
                    'c.fecha_ingreso',
                    'c.posicion',
                    'c.iso_code',
                    'c.marca',
                    'c.naviera',
                    'c.transportista',
                    'c.conductor',
                    'c.placa_cabezal',
                    'c.placa_chasis',
                    't.nombre as tipo',
                    'm.nombre as modelo',
                    'eu.nombre as estado_unidad',
                    'eb.nombre as estado_box',
                    'ei.nombre as ipsa',
                    'p.nombre as patio',
                    'b.numero as bloque',
                ])
                ->first();
        }
        abort_unless($container, 404, 'Selecciona un contenedor registrado en la sucursal activa.');

        $issuedAt = $container?->fecha_ingreso
            ? Carbon::parse($container->fecha_ingreso)
            : now();
        $prefix = $operation === 'ingreso' ? 'EIR-IN' : 'EIR-OUT';
        $folioSequence = str_pad((string) ($container->id ?? $request->integer('contenedor_id') ?: 1), 3, '0', STR_PAD_LEFT);

        return [
            'numero' => $prefix.'-'.$issuedAt->format('Ymd').'-'.$folioSequence,
            'fecha' => $issuedAt->format('d/m/Y'),
            'hora' => $issuedAt->format('H:i').' hrs',
            'equipo' => $container->nombre ?? 'SIN-EQUIPO',
            'tipo' => $container->tipo ?? '-',
            'modelo' => $container->modelo ?? '-',
            'iso_code' => $container->iso_code ?? '-',
            'marca' => $container->marca ?? '-',
            'estado_unidad' => $container->estado_unidad ?? '-',
            'estado_box' => $container->estado_box ?? '-',
            'ipsa' => $container->ipsa ?? '-',
            'patio' => $container->patio ?? '-',
            'bloque' => $container->bloque ?? '-',
            'posicion' => $container->posicion ?? '-',
            'naviera' => $container->naviera ?? 'Pendiente',
            'cliente' => 'Pendiente',
            'transportista' => $container->transportista ?? 'Pendiente',
            'conductor' => $container->conductor ?: 'Pendiente',
            'licencia' => 'Pendiente',
            'placa_cabezal' => $container->placa_cabezal ?? 'Pendiente',
            'placa_chasis' => $container->placa_chasis ?: 'Pendiente',
            'marchamo' => 'Pendiente',
            'referencia' => $operation === 'ingreso'
                ? 'Constancia visual de recepcion de ingreso.'
                : 'Constancia visual de salida por despacho.',
            'usuario' => $request->user()->username,
        ];
    }

    private function dispatchDocuments(int $branchId): Builder
    {
        return DB::table('container_assignment_events as e')->join('container_ingress_cycles as cycle', 'cycle.id', '=', 'e.ingress_cycle_id')
            ->where('cycle.branch_id', $branchId)->whereColumn('cycle.container_id', 'e.container_id')->where('e.event_type', 'dispatch')
            ->select('e.*')->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.document.numero')) as folio")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.document.equipo')) as equipo")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.document.fecha')) as fecha");
    }

    private function dispatchDocumentData(Request $request, int $branchId): array
    {
        $request->validate(['event_id' => ['nullable', 'integer', 'min:1'], 'contenedor_id' => ['nullable', 'integer', 'min:1']]);
        abort_unless($request->filled('event_id') || $request->filled('contenedor_id'), 404, 'Selecciona una constancia de salida registrada.');
        $event = $this->dispatchDocuments($branchId)
            ->when($request->filled('event_id'), fn ($query) => $query->where('e.id', $request->integer('event_id')))
            ->when($request->filled('contenedor_id'), fn ($query) => $query->where('e.container_id', $request->integer('contenedor_id')))
            ->latest('e.id')->firstOrFail();

        return json_decode($event->after_data, true, 512, JSON_THROW_ON_ERROR)['document'] + ['event_id' => $event->id];
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');
        app(ExternalAuditAccess::class)->authorizeBranch($request->user(), $branchId);

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
