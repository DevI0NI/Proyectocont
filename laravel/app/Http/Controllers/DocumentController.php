<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
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

    public function ingressTicketPdf(Request $request)
    {
        return $this->ticketPdf($request, 'ingreso');
    }

    public function dispatchEir(Request $request): View
    {
        return $this->documentView($request, 'documents.eir', 'salida');
    }

    public function dispatchTicket(Request $request): View
    {
        return $this->documentView($request, 'documents.ticket', 'salida');
    }

    public function dispatchTicketPdf(Request $request)
    {
        return $this->ticketPdf($request, 'salida');
    }

    private function ticketPdf(Request $request, string $operation)
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        if (! class_exists(Dompdf::class)) {
            require_once base_path('../vendor/autoload.php');
        }

        $data = $this->documentData($request, $branch->id, $operation);
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
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function documentView(Request $request, string $view, string $operation): View
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        return view($view, [
            'sucursal' => $branch,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'operation' => $operation,
            'data' => $this->documentData($request, $branch->id, $operation),
        ]);
    }

    private function documentData(Request $request, int $branchId, string $operation): array
    {
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

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
