<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><style>
    @page { margin: 28px 24px 38px; }
    body { font-family: DejaVu Sans, Arial, sans-serif; color: #17322e; font-size: 10px; }
    .header-band { background: #0b5d57; color: #fff; padding: 14px 18px; border-radius: 8px 8px 0 0; }
    .header-table { width: 100%; border-collapse: collapse; }
    .header-logo { width: 84px; vertical-align: middle; }
    .header-title { text-align: center; font-size: 20px; font-weight: bold; }
    .title-box { background: #eef7f5; border: 1px solid #cde1dc; border-top: none; padding: 14px 18px; border-radius: 0 0 8px 8px; margin-bottom: 18px; }
    h1 { font-size: 19px; color: #0b5d57; margin: 0 0 10px; }
    .meta-item { margin: 3px 0; color: #36514c; }
    .report-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .report-table thead { display: table-header-group; }
    .report-table th { background: #0e746b; color: #fff; font-size: 9px; font-weight: bold; padding: 8px 5px; border: 1px solid #0b5d57; overflow-wrap: break-word; }
    .report-table td { border: 1px solid #d6e4e1; padding: 7px 6px; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
    .report-table tbody tr:nth-child(even) td { background: #f8fbfb; }
    .empty-state { text-align: center; color: #6a7c78; font-style: italic; padding: 30px; }
</style></head>
<body>
    <div class="header-band"><table class="header-table"><tr><td class="header-logo">@if($logo)<img src="{{ $logo }}" alt="IPSA" height="52">@endif</td><td class="header-title">SISTEMA PORTUARIO PROPORTSA</td></tr></table></div>
    <div class="title-box"><h1>{{ $title }}</h1>@foreach($meta as $label => $value)<div class="meta-item"><strong>{{ $label }}:</strong> {{ $value }}</div>@endforeach</div>
    <table class="report-table"><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>
        @forelse($rows as $row)<tr>@foreach($row as $index => $value)<td>{{ is_numeric($value) && str_contains($headers[$index], '(GTQ)') ? 'Q '.number_format((float)$value, 2) : $value }}</td>@endforeach</tr>@empty<tr><td class="empty-state" colspan="{{ count($headers) }}">No se encontraron registros para exportar.</td></tr>@endforelse
    </tbody></table>
</body></html>
