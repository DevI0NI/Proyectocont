@extends('layouts.app')
@section('content')
    @if(in_array($catalog, ['navieras', 'auditoras'], true))
        @include('parameters.catalogs.shipping-lines-form')
    @elseif($catalog === 'iso')
        @include('parameters.catalogs.iso-form')
    @elseif(in_array($catalog, ['repuestos', 'reparaciones'], true))
        @include('parameters.catalogs.parts-form')
    @else
@php($editing = isset($record['id']))
<section class="page-heading"><div><span class="eyebrow">{{ $definition['title'] }}</span><h1>{{ $editing ? 'Editar' : 'Crear' }} {{ $definition['singular'] }}</h1></div><a class="button secondary" href="{{ route('catalogs.index', $catalog) }}">Volver</a></section>
@include('parameters.catalogs.nav')
<section class="form-panel">
@if($errors->any())<div class="form-error page-error">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ $editing ? route('catalogs.update', ['catalog' => $catalog, 'record' => $record['id']]) : route('catalogs.store', $catalog) }}" class="record-form catalog-form">
@csrf @if($editing) @method('PATCH') @endif
<div class="catalog-field-grid">
@unless(in_array($catalog, ['navieras', 'auditoras'], true))
<label><span>Codigo{{ $catalog === 'iso' ? ' ISO' : '' }}</span><input name="code" value="{{ old('code', $record['code'] ?? '') }}" maxlength="{{ $catalog === 'iso' ? 4 : 30 }}" required autocomplete="off" class="catalog-code"></label>
@endunless
<label><span>Nombre</span><input name="name" value="{{ old('name', $record['name'] ?? '') }}" maxlength="120" required></label>
</div>
<label><span>Descripcion / observaciones</span><textarea name="description" maxlength="255" rows="3">{{ old('description', $record['description'] ?? '') }}</textarea></label>
@if(in_array($catalog, ['navieras', 'auditoras'], true))
<div class="catalog-field-grid">
<label><span>Persona de contacto</span><input name="contact_name" value="{{ old('contact_name', $record['contact_name'] ?? '') }}" maxlength="120"></label>
<label><span>Correo</span><input type="email" name="email" value="{{ old('email', $record['email'] ?? '') }}" maxlength="150"></label>
<label><span>Telefono</span><input name="phone" value="{{ old('phone', $record['phone'] ?? '') }}" maxlength="30" type="tel"></label>
</div>
@endif
@if($catalog === 'navieras')
<fieldset class="catalog-relations"><legend>Sucursales de operacion</legend>
@forelse($branches as $branch)<label class="catalog-check"><input type="checkbox" name="branches[]" value="{{ $branch->id }}" @checked(in_array($branch->id, old('branches', $record['branches'] ?? [])))><span>{{ $branch->nombre }}{{ (int)$branch->estado_id !== 1 ? ' (inactiva)' : '' }}</span></label>@empty<p class="table-muted">No hay sucursales activas.</p>@endforelse
</fieldset>
<fieldset class="catalog-relations"><legend>Auditoras vinculadas</legend>
@forelse($auditors as $auditor)<label class="catalog-check"><input type="checkbox" name="auditors[]" value="{{ $auditor->id }}" @checked(in_array($auditor->id, old('auditors', $record['auditors'] ?? [])))><span>{{ $auditor->name }}{{ (int)$auditor->status !== 1 ? ' (inactiva)' : '' }}</span></label>@empty<p class="table-muted">Sin auditoras registradas. La vinculacion puede quedar pendiente.</p>@endforelse
</fieldset>
@endif
@if($catalog === 'iso')
<div class="catalog-field-grid">
<label><span>Tipo de contenedor</span><input name="type_name" value="{{ old('type_name', $record['type_name'] ?? '') }}" maxlength="80" required></label>
@foreach(['length_mm' => 'Largo', 'width_mm' => 'Ancho', 'height_mm' => 'Alto'] as $field => $label)<label><span>{{ $label }} exterior (mm)</span><input type="number" name="{{ $field }}" value="{{ old($field, $record[$field] ?? '') }}" min="1" max="{{ $field === 'length_mm' ? 50000 : 10000 }}" step="1" required></label>@endforeach
</div>
<label><span>Referencia tecnica</span><input type="url" name="source_url" value="{{ old('source_url', $record['source_url'] ?? '') }}" maxlength="500" placeholder="https://" required></label>
@endif
@if(in_array($catalog, ['repuestos', 'reparaciones'], true))
<div class="catalog-field-grid">
<label><span>Unidad de {{ $catalog === 'reparaciones' ? 'cobro' : 'medida' }}</span><input name="unit" value="{{ old('unit', $record['unit'] ?? '') }}" maxlength="30" required></label>
<label><span>{{ $catalog === 'reparaciones' ? 'Tarifa' : 'Costo' }} de referencia</span><input name="reference_cost" type="number" value="{{ old('reference_cost', $record['reference_cost'] ?? '') }}" min="0" max="99999999.99" step="0.01"></label>
</div>
<fieldset class="catalog-currency"><legend>Moneda</legend>@foreach(['GTQ', 'USD'] as $currency)<label class="catalog-check"><input type="radio" name="currency" value="{{ $currency }}" @checked(old('currency', $record['currency'] ?? 'GTQ') === $currency)>{{ $currency }}</label>@endforeach</fieldset>
@endif
<div class="form-actions-row"><a class="button secondary" href="{{ route('catalogs.index', $catalog) }}">Cancelar</a><button class="button primary" type="submit">Guardar</button></div>
</form></section>
    @endif
@endsection
