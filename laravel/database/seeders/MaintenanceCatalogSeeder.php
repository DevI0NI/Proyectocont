<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaintenanceCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $catalogs = [
            'repair_services' => [
                ['Soldadura de panel', 'Servicio'], ['Reparacion de estructura', 'Servicio'],
                ['Cambio de panel', 'Servicio'], ['Reparacion de piso', 'Servicio'],
                ['Cambio de empaque de puerta', 'Servicio'], ['Reparacion de bisagras', 'Servicio'],
                ['Ajuste de barras de cierre', 'Servicio'], ['Sellado de filtraciones', 'Servicio'],
                ['Reparacion electrica', 'Servicio'], ['Reparacion de refrigeracion', 'Servicio'],
                ['Tratamiento de corrosion', 'Servicio'], ['Pintura de zona reparada', 'Servicio'],
            ],
            'repair_parts' => [
                ['Bisagra', 'Unidad'], ['Lamina de acero', 'Lamina'], ['Perfil estructural', 'Metro'],
                ['Tablon para piso', 'Pieza'], ['Empaque de puerta', 'Metro'], ['Tornillo', 'Unidad'],
                ['Tuerca', 'Unidad'], ['Remache', 'Unidad'], ['Electrodo de soldadura', 'Kilogramo'],
                ['Alambre de soldadura', 'Kilogramo'], ['Cartucho de sellador', 'Unidad'],
                ['Pintura', 'Litro'], ['Anticorrosivo', 'Litro'], ['Cable electrico', 'Metro'],
                ['Fusible', 'Unidad'], ['Barra de cierre', 'Unidad'],
            ],
        ];
        DB::transaction(function () use ($catalogs): void {
            foreach ($catalogs as $table => $records) {
                $names = DB::table($table)->pluck('name')->map(fn (string $name): string => Str::lower(Str::ascii(trim($name))));
                foreach ($records as $index => [$name, $unit]) {
                    $code = ($table === 'repair_services' ? 'RPR' : 'REP').'-BASE-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
                    if ($names->contains(Str::lower(Str::ascii($name))) || DB::table($table)->where('code', $code)->exists()) {
                        continue;
                    }
                    DB::table($table)->insert(['code' => $code, 'name' => $name, 'unit' => $unit,
                        'reference_cost' => null, 'currency' => 'GTQ', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
